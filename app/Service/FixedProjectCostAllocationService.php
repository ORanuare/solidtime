<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\ProjectBillingType;
use App\Enums\TimeEntryAggregationType;
use App\Enums\TimeEntryRoundingType;
use App\Enums\Weekday;
use App\Models\TimeEntry;
use Carbon\CarbonTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Allocates each fixed-price project's contract total across aggregate report rows in proportion
 * to billable time on that project within the **same query filter** (date range, member, client,
 * tags, billable flag, rounding, etc.). This is recognition for reporting views only—not lifetime
 * revenue under accounting rules: changing the filter changes how much of each fixed fee appears.
 *
 * Rounding uses largest remainder in minor units so amounts for a given project sum to exactly
 * that project's fixed_price within the active filter.
 */
class FixedProjectCostAllocationService
{
    private const string KEY_SEP = "\x1e";

    public function __construct(
        private TimeEntryService $timeEntryService,
        private TimezoneService $timezoneService,
    ) {}

    /**
     * @param  Builder<TimeEntry>  $filteredQuery  Filtered time entries (no select/group/order).
     * @return array{
     *     total_fixed_cents: int,
     *     leaf: array<string, int>,
     *     parent_when_tag_subgroup: array<string, int>
     * }
     */
    public function computeFixedAllocationMaps(
        Builder $filteredQuery,
        ?TimeEntryAggregationType $group1Type,
        ?TimeEntryAggregationType $group2Type,
        string $timezone,
        Weekday $startOfWeek,
        ?TimeEntryRoundingType $roundingType,
        ?int $roundingMinutes,
    ): array {
        $startRaw = $this->timeEntryService->getStartSelectRawForRounding($roundingType, $roundingMinutes);
        $endRaw = $this->timeEntryService->getEndSelectRawForRounding($roundingType, $roundingMinutes);
        $durationExpr = 'round(sum(extract(epoch from ('.$endRaw.' - '.$startRaw.'))))';

        $totalsPerProject = $this->billableSecondsPerFixedProject($filteredQuery, $startRaw, $endRaw);
        if ($totalsPerProject->isEmpty()) {
            return [
                'total_fixed_cents' => 0,
                'leaf' => [],
                'parent_when_tag_subgroup' => [],
            ];
        }

        $totalFixedCents = 0;
        foreach ($totalsPerProject as $row) {
            if ((int) $row->aggregate > 0) {
                $totalFixedCents += (int) $row->fixed_price;
            }
        }

        if ($group1Type === null) {
            return [
                'total_fixed_cents' => $totalFixedCents,
                'leaf' => [],
                'parent_when_tag_subgroup' => [],
            ];
        }

        $group1Select = $this->getGroupBySelectExpression($group1Type, $timezone, $startOfWeek);
        $group2Select = $group2Type !== null
            ? $this->getGroupBySelectExpression($group2Type, $timezone, $startOfWeek)
            : null;

        $leaf = [];
        $parentWhenTagSubgroup = [];

        foreach ($totalsPerProject as $row) {
            $projectId = (string) $row->project_id;
            $fixedPrice = (int) $row->fixed_price;
            $tP = (int) $row->aggregate;
            if ($tP <= 0 || $fixedPrice <= 0) {
                continue;
            }

            $b = $filteredQuery->clone();
            $this->applyTagCrossJoinForAggregation($b, $group1Type, $group2Type);
            $b->join('projects as fixed_price_projects', function ($join): void {
                $join->on('time_entries.project_id', '=', 'fixed_price_projects.id')
                    ->where('fixed_price_projects.billing_type', '=', ProjectBillingType::Fixed->value)
                    ->whereNotNull('fixed_price_projects.fixed_price');
            });
            $b->where('time_entries.billable', '=', true)
                ->where('time_entries.project_id', '=', $projectId);

            if ($group2Type === TimeEntryAggregationType::Tag) {
                $basePerG1 = $this->aggregateByGroup1ProjectSeconds(
                    $filteredQuery,
                    $group1Select,
                    $projectId,
                    $startRaw,
                    $endRaw
                );
                $expanded = $this->runGroupedSelect($b, $group1Select, $group2Select, $durationExpr, ['group_1', 'group_2']);
                $byG1 = [];
                foreach ($expanded as $er) {
                    $g1 = $this->normalizeSqlGroupValue($er->group_1);
                    $g2 = $this->normalizeSqlGroupValue($er->group_2);
                    $byG1[$g1][] = ['g2' => $g2, 'seconds' => (int) $er->aggregate];
                }
                foreach ($byG1 as $g1 => $rows) {
                    $sBase = $basePerG1[$g1] ?? 0;
                    if ($sBase <= 0) {
                        continue;
                    }
                    $targetCents = (int) round($fixedPrice * $sBase / $tP);
                    $weights = array_map(static fn (array $r): int => $r['seconds'], $rows);
                    $alloc = $this->allocateByLargestRemainder($targetCents, $weights);
                    foreach ($rows as $idx => $r) {
                        $add = $alloc[$idx];
                        if ($add === 0) {
                            continue;
                        }
                        $k = $this->compositeKey($g1, $r['g2']);
                        $leaf[$k] = ($leaf[$k] ?? 0) + $add;
                    }
                    $parentWhenTagSubgroup[$g1] = ($parentWhenTagSubgroup[$g1] ?? 0) + $targetCents;
                }
            } elseif ($group1Type === TimeEntryAggregationType::Tag) {
                $expanded = $this->runGroupedSelect($b, $group1Select, null, $durationExpr, ['group_1']);
                $weights = [];
                $g1Keys = [];
                foreach ($expanded as $er) {
                    $g1Keys[] = $this->normalizeSqlGroupValue($er->group_1);
                    $weights[] = (int) $er->aggregate;
                }
                $alloc = $this->allocateByLargestRemainder($fixedPrice, $weights);
                foreach ($g1Keys as $idx => $g1) {
                    $add = $alloc[$idx];
                    if ($add === 0) {
                        continue;
                    }
                    $k = $this->compositeKey($g1, null);
                    $leaf[$k] = ($leaf[$k] ?? 0) + $add;
                }
            } else {
                $groupBy = $group2Select !== null ? ['group_1', 'group_2'] : ['group_1'];
                $expanded = $this->runGroupedSelect($b, $group1Select, $group2Select, $durationExpr, $groupBy);
                $weights = [];
                $pairs = [];
                foreach ($expanded as $er) {
                    $g1 = $this->normalizeSqlGroupValue($er->group_1);
                    $g2 = $group2Select !== null ? $this->normalizeSqlGroupValue($er->group_2) : null;
                    $pairs[] = [$g1, $g2];
                    $weights[] = (int) $er->aggregate;
                }
                $alloc = $this->allocateByLargestRemainder($fixedPrice, $weights);
                foreach ($pairs as $idx => [$g1, $g2]) {
                    $add = $alloc[$idx];
                    if ($add === 0) {
                        continue;
                    }
                    $k = $this->compositeKey($g1, $g2);
                    $leaf[$k] = ($leaf[$k] ?? 0) + $add;
                }
            }
        }

        return [
            'total_fixed_cents' => $totalFixedCents,
            'leaf' => $leaf,
            'parent_when_tag_subgroup' => $parentWhenTagSubgroup,
        ];
    }

    /**
     * @param  Builder<TimeEntry>  $filteredQuery
     * @return Collection<int, object{project_id: string, fixed_price: int, aggregate: int}>
     */
    private function billableSecondsPerFixedProject(Builder $filteredQuery, string $startRaw, string $endRaw): Collection
    {
        $q = $filteredQuery->clone();
        $q->join('projects as fixed_price_projects', function ($join): void {
            $join->on('time_entries.project_id', '=', 'fixed_price_projects.id')
                ->where('fixed_price_projects.billing_type', '=', ProjectBillingType::Fixed->value)
                ->whereNotNull('fixed_price_projects.fixed_price');
        })
            ->where('time_entries.billable', '=', true)
            ->whereNotNull('time_entries.project_id')
            ->selectRaw(
                'fixed_price_projects.id as project_id,'.
                ' fixed_price_projects.fixed_price as fixed_price,'.
                ' round(sum(extract(epoch from ('.$endRaw.' - '.$startRaw.')))) as aggregate'
            )
            ->groupBy('fixed_price_projects.id', 'fixed_price_projects.fixed_price');

        /** @var Collection<int, object{project_id: string, fixed_price: int, aggregate: int}> */
        return $q->get();
    }

    /**
     * @param  Builder<TimeEntry>  $filteredQuery
     * @return array<string, int>
     */
    private function aggregateByGroup1ProjectSeconds(
        Builder $filteredQuery,
        string $group1Select,
        string $projectId,
        string $startRaw,
        string $endRaw,
    ): array {
        $q = $filteredQuery->clone();
        $q->join('projects as fixed_price_projects', function ($join): void {
            $join->on('time_entries.project_id', '=', 'fixed_price_projects.id')
                ->where('fixed_price_projects.billing_type', '=', ProjectBillingType::Fixed->value)
                ->whereNotNull('fixed_price_projects.fixed_price');
        })
            ->where('time_entries.billable', '=', true)
            ->where('time_entries.project_id', '=', $projectId)
            ->selectRaw(
                $group1Select.' as group_1,'.
                ' round(sum(extract(epoch from ('.$endRaw.' - '.$startRaw.')))) as aggregate'
            )
            ->groupBy('group_1');
        $out = [];
        foreach ($q->get() as $r) {
            $g1 = $this->normalizeSqlGroupValue($r->group_1);
            $out[$g1] = (int) $r->aggregate;
        }

        return $out;
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    public function applyTagCrossJoinForAggregation(
        Builder $query,
        ?TimeEntryAggregationType $group1Type,
        ?TimeEntryAggregationType $group2Type,
    ): void {
        if (($group1Type === TimeEntryAggregationType::Tag) || ($group2Type === TimeEntryAggregationType::Tag)) {
            $query->crossJoin(DB::raw(
                "LATERAL (\n".
                "  SELECT jsonb_array_elements_text(coalesce(tags, '[]'::jsonb)) AS tag\n".
                "  UNION ALL\n".
                "  SELECT ''::text AS tag WHERE coalesce(jsonb_array_length(tags), 0) = 0\n".
                ') AS tag(tag)'
            ));
        }
    }

    public function getGroupBySelectExpression(
        TimeEntryAggregationType $group,
        string $timezone,
        Weekday $startOfWeek,
    ): string {
        $te = (new TimeEntry)->getTable();
        $timezoneShift = $this->timezoneService->getShiftFromUtc(new CarbonTimeZone($timezone));
        if ($timezoneShift > 0) {
            $dateWithTimeZone = $te.'.start + INTERVAL \''.$timezoneShift.' second\'';
        } elseif ($timezoneShift < 0) {
            $dateWithTimeZone = $te.'.start - INTERVAL \''.abs($timezoneShift).' second\'';
        } else {
            $dateWithTimeZone = $te.'.start';
        }
        $startOfWeekString = Carbon::now()->setTimezone($timezone)->startOfWeek($startOfWeek->carbonWeekDay())->toDateTimeString();
        if ($group === TimeEntryAggregationType::Day) {
            return 'date('.$dateWithTimeZone.')';
        }
        if ($group === TimeEntryAggregationType::Week) {
            return "to_char(date_bin('7 days', ".$dateWithTimeZone.", timestamp '".$startOfWeekString."'), 'YYYY-MM-DD')";
        }
        if ($group === TimeEntryAggregationType::Month) {
            return 'to_char('.$dateWithTimeZone.', \'YYYY-MM\')';
        }
        if ($group === TimeEntryAggregationType::Year) {
            return 'to_char('.$dateWithTimeZone.', \'YYYY\')';
        }
        if ($group === TimeEntryAggregationType::User) {
            return $te.'.user_id';
        }
        if ($group === TimeEntryAggregationType::Project) {
            return $te.'.project_id';
        }
        if ($group === TimeEntryAggregationType::Task) {
            return $te.'.task_id';
        }
        if ($group === TimeEntryAggregationType::Client) {
            return $te.'.client_id';
        }
        if ($group === TimeEntryAggregationType::Billable) {
            return $te.'.billable';
        }
        if ($group === TimeEntryAggregationType::Description) {
            return $te.'.description';
        }

        return 'tag';
    }

    /**
     * @param  list<string>  $groupBy
     * @return Collection<int, object{group_1: mixed, group_2?: mixed, aggregate: int|string}>
     */
    private function runGroupedSelect(
        Builder $b,
        string $group1Select,
        ?string $group2Select,
        string $durationExpr,
        array $groupBy,
    ): Collection {
        $select = $group1Select.' as group_1,';
        if ($group2Select !== null) {
            $select .= ' '.$group2Select.' as group_2,';
        }
        $select .= ' '.$durationExpr.' as aggregate';
        $b->selectRaw($select)->groupBy($groupBy);

        return $b->get();
    }

    private function normalizeSqlGroupValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function compositeKey(string $g1, ?string $g2): string
    {
        return $g1.self::KEY_SEP.($g2 ?? '');
    }

    /**
     * @param  list<float|int>  $weights
     * @return list<int>
     */
    private function allocateByLargestRemainder(int $totalCents, array $weights): array
    {
        $n = count($weights);
        if ($n === 0 || $totalCents === 0) {
            return array_fill(0, $n, 0);
        }
        $sumW = 0.0;
        foreach ($weights as $w) {
            $sumW += max(0.0, (float) $w);
        }
        if ($sumW <= 0) {
            return array_fill(0, $n, 0);
        }
        $floors = [];
        $frac = [];
        foreach ($weights as $i => $w) {
            $w = max(0.0, (float) $w);
            $exact = $totalCents * ($w / $sumW);
            $f = (int) floor($exact + 1e-9);
            $floors[$i] = $f;
            $frac[$i] = $exact - $f;
        }
        $allocated = (int) array_sum($floors);
        $remainder = $totalCents - $allocated;
        $order = range(0, $n - 1);
        usort($order, static function (int $a, int $b) use ($frac): int {
            return $frac[$b] <=> $frac[$a];
        });
        for ($r = 0; $r < $remainder; $r++) {
            $floors[$order[$r]]++;
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[$i] = $floors[$i];
        }

        return $out;
    }
}
