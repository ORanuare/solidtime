<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\TimeEntryAggregationType;
use App\Enums\TimeEntryAggregationTypeInterval;
use App\Enums\TimeEntryRoundingType;
use App\Enums\Weekday;
use App\Models\Client;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TimeEntryAggregationService
{
    public function __construct(
        private FixedProjectCostAllocationService $fixedProjectCostAllocationService,
        private TimeEntryService $timeEntryService,
    ) {}

    /**
     * @param  Builder<TimeEntry>  $timeEntriesQuery
     * @return array{
     *       grouped_type: string|null,
     *       grouped_data: null|array<array{
     *           key: string|null,
     *           seconds: int,
     *           cost: int|null,
     *           grouped_type: string|null,
     *           grouped_data: null|array<array{
     *               key: string|null,
     *               seconds: int,
     *               cost: int|null,
     *               grouped_type: null,
     *               grouped_data: null
     *           }>
     *       }>,
     *       seconds: int,
     *       cost: int|null
     * }
     */
    public function getAggregatedTimeEntries(Builder $timeEntriesQuery, ?TimeEntryAggregationType $group1Type, ?TimeEntryAggregationType $group2Type, string $timezone, Weekday $startOfWeek, bool $fillGapsInTimeGroups, ?Carbon $start, ?Carbon $end, bool $showBillableRate, ?TimeEntryRoundingType $roundingType, ?int $roundingMinutes): array
    {
        $fillGapsInTimeGroupsIsPossible = $fillGapsInTimeGroups && $start !== null && $end !== null;
        /** @var Builder<TimeEntry> $baseTotalsQuery */
        $baseTotalsQuery = $timeEntriesQuery->clone();
        $group1Select = null;
        $group2Select = null;
        $groupBy = null;
        // If any grouping is by tag, expand rows per tag and ensure a NULL row for entries without tags
        $this->fixedProjectCostAllocationService->applyTagCrossJoinForAggregation($timeEntriesQuery, $group1Type, $group2Type);
        if ($group1Type !== null) {
            $group1Select = $this->getGroupByQuery($group1Type, $timezone, $startOfWeek);
            $groupBy = ['group_1'];
            if ($group2Type !== null) {
                $group2Select = $this->getGroupByQuery($group2Type, $timezone, $startOfWeek);
                $groupBy = ['group_1', 'group_2'];
            }
        }

        $startRawSelect = $this->timeEntryService->getStartSelectRawForRounding($roundingType, $roundingMinutes);
        $endRawSelect = $this->timeEntryService->getEndSelectRawForRounding($roundingType, $roundingMinutes);

        $timeEntriesQuery->selectRaw(
            ($group1Select !== null ? $group1Select.' as group_1,' : '').
            ($group2Select !== null ? $group2Select.' as group_2,' : '').
            ' round(sum(extract(epoch from ('.$endRawSelect.' - '.$startRawSelect.')))) as aggregate,'.
            ' round(sum(extract(epoch from ('.$endRawSelect.' - '.$startRawSelect.')) * (coalesce(billable_rate, 0)::float/60/60))) as cost'
        );
        if ($groupBy !== null) {
            $timeEntriesQuery->groupBy($groupBy);
        }
        if ($group1Select !== null) {
            $timeEntriesQuery->orderBy('group_1');
            if ($group2Select !== null) {
                $timeEntriesQuery->orderBy('group_2');
            }
        }

        $timeEntriesAggregates = $timeEntriesQuery->get();

        if ($group1Select !== null) {
            $groupedAggregates = $timeEntriesAggregates->groupBy($group2Select !== null ? ['group_1', 'group_2'] : ['group_1']);

            $group1Response = [];
            $group1ResponseSum = 0;
            $group1ResponseCost = 0;
            // If Tag is subgroup, prepare base totals per primary group without tag expansion
            $baseTotalsPerGroup1Map = [];
            if ($group2Type === TimeEntryAggregationType::Tag) {
                $baseTotalsPerGroup1Query = $baseTotalsQuery->clone();
                $baseTotalsPerGroup1 = $baseTotalsPerGroup1Query
                    ->selectRaw(
                        $group1Select.' as group_1,'.
                        ' round(sum(extract(epoch from ('.$endRawSelect.' - '.$startRawSelect.')))) as aggregate,'.
                        ' round(sum(extract(epoch from ('.$endRawSelect.' - '.$startRawSelect.')) * (coalesce(billable_rate, 0)::float/60/60))) as cost'
                    )
                    ->groupBy('group_1')
                    ->get();
                foreach ($baseTotalsPerGroup1 as $row) {
                    /** @var object{group_1: mixed, aggregate: int|null, cost: int|null} $row */
                    $baseTotalsPerGroup1Map[(string) ($row->group_1 ?? '')] = [
                        'aggregate' => (int) ($row->aggregate ?? 0),
                        'cost' => (int) ($row->cost ?? 0),
                    ];
                }
            }
            foreach ($groupedAggregates as $group1 => $group1Aggregates) {
                /** @var string|int $group1 */
                $group2Response = [];
                if ($group2Select !== null) {
                    $group2ResponseSum = 0;
                    $group2ResponseCost = 0;
                    foreach ($group1Aggregates as $group2 => $aggregate) {
                        /** @var string|int $group2 */
                        /** @var Collection<int, object{aggregate: int, cost: int}> $aggregate */
                        $group2Response[] = [
                            'key' => $group2 === '' ? null : (string) $group2,
                            'seconds' => (int) $aggregate->get(0)->aggregate,
                            'cost' => $showBillableRate ? (int) $aggregate->get(0)->cost : null,
                            'grouped_type' => null,
                            'grouped_data' => null,
                        ];
                        $group2ResponseSum += (int) $aggregate->get(0)->aggregate;
                        $group2ResponseCost += (int) $aggregate->get(0)->cost;
                    }
                    // Override primary group totals when Tag is subgroup to avoid double counting
                    if ($group2Type === TimeEntryAggregationType::Tag) {
                        $keyForMap = (string) $group1;
                        if (array_key_exists($keyForMap, $baseTotalsPerGroup1Map)) {
                            $group2ResponseSum = $baseTotalsPerGroup1Map[$keyForMap]['aggregate'];
                            $group2ResponseCost = $baseTotalsPerGroup1Map[$keyForMap]['cost'];
                        }
                    }
                } else {
                    /** @var Collection<int, object{aggregate: int, cost: int}> $group1Aggregates */
                    $group2ResponseSum = (int) $group1Aggregates->get(0)->aggregate;
                    $group2ResponseCost = (int) $group1Aggregates->get(0)->cost;
                    $group2Response = null;
                }

                $group1Response[] = [
                    'key' => $group1 === '' ? null : (string) $group1,
                    'seconds' => $group2ResponseSum,
                    'cost' => $showBillableRate ? $group2ResponseCost : null,
                    'grouped_type' => $group2Type?->value,
                    'grouped_data' => $group2Response,
                ];
                $group1ResponseSum += $group2ResponseSum;
                $group1ResponseCost += $group2ResponseCost;
            }

            // If Tag is selected in any grouping, compute overall totals from base (non-tag-expanded) query to avoid double counting
            $hasTagGrouping = ($group1Type === TimeEntryAggregationType::Tag) || ($group2Type === TimeEntryAggregationType::Tag);
            if ($hasTagGrouping) {
                $baseTotals = $baseTotalsQuery
                    ->clone()
                    ->selectRaw(
                        ' round(sum(extract(epoch from ('.$endRawSelect.' - '.$startRawSelect.')))) as aggregate,'.
                        ' round(sum(extract(epoch from ('.$endRawSelect.' - '.$startRawSelect.')) * (coalesce(billable_rate, 0)::float/60/60))) as cost'
                    )
                    ->first();
                if ($baseTotals !== null) {
                    /** @var object{aggregate: int|null, cost: int|null} $baseTotals */
                    $group1ResponseSum = (int) ($baseTotals->aggregate ?? 0);
                    $group1ResponseCost = (int) ($baseTotals->cost ?? 0);
                }
            }

            if ($fillGapsInTimeGroupsIsPossible) {
                $group1Response = $this->fillGapsInTimeGroups($group1Response, $group1Type, $group2Type, $timezone, $startOfWeek, $start, $end);
            }
        } else {
            $group1Response = null;
            /** @var Collection<int, object{aggregate: int, cost: int}> $timeEntriesAggregates */
            $group1ResponseSum = (int) $timeEntriesAggregates->get(0)->aggregate;
            $group1ResponseCost = (int) $timeEntriesAggregates->get(0)->cost;
        }

        $result = [
            'seconds' => $group1ResponseSum,
            'cost' => $showBillableRate ? $group1ResponseCost : null,
            'grouped_type' => $group1Type?->value,
            'grouped_data' => $group1Response,
        ];

        if ($showBillableRate) {
            $hasTagGrouping = ($group1Type === TimeEntryAggregationType::Tag) || ($group2Type === TimeEntryAggregationType::Tag);
            $fixedMaps = $this->fixedProjectCostAllocationService->computeFixedAllocationMaps(
                $baseTotalsQuery->clone(),
                $group1Type,
                $group2Type,
                $timezone,
                $startOfWeek,
                $roundingType,
                $roundingMinutes,
            );
            $this->mergeFixedProjectCostsIntoAggregatedResult(
                $result,
                $fixedMaps,
                $group1Type,
                $group2Type,
                $hasTagGrouping
            );
        }

        return $result;
    }

    /**
     * @param  array{
     *     seconds: int,
     *     cost: int|null,
     *     grouped_type: string|null,
     *     grouped_data: null|array<int, array<string, mixed>>
     * }  $result
     * @param  array{
     *     total_fixed_cents: int,
     *     leaf: array<string, int>,
     *     parent_when_tag_subgroup: array<string, int>
     * }  $maps
     */
    private function mergeFixedProjectCostsIntoAggregatedResult(
        array &$result,
        array $maps,
        ?TimeEntryAggregationType $group1Type,
        ?TimeEntryAggregationType $group2Type,
        bool $hasTagGrouping,
    ): void {
        if ($maps['total_fixed_cents'] === 0 && $maps['leaf'] === [] && $maps['parent_when_tag_subgroup'] === []) {
            return;
        }

        $sep = "\x1e";
        $norm = static fn (?string $k): string => $k ?? '';
        $leaf = $maps['leaf'];
        $parentTag = $maps['parent_when_tag_subgroup'];

        if ($result['grouped_data'] === null) {
            $result['cost'] = (int) ($result['cost'] ?? 0) + $maps['total_fixed_cents'];

            return;
        }

        foreach ($result['grouped_data'] as &$g1) {
            $k1 = $norm($g1['key'] ?? null);
            if ($g1['grouped_data'] === null) {
                $ck = $k1.$sep;
                $add = $leaf[$ck] ?? 0;
                $g1['cost'] = (int) ($g1['cost'] ?? 0) + $add;
            } else {
                $subSum = 0;
                foreach ($g1['grouped_data'] as &$g2) {
                    $k2 = $norm($g2['key'] ?? null);
                    $ck = $k1.$sep.$k2;
                    $add = $leaf[$ck] ?? 0;
                    $g2['cost'] = (int) ($g2['cost'] ?? 0) + $add;
                    $subSum += $add;
                }
                unset($g2);
                if ($group2Type === TimeEntryAggregationType::Tag) {
                    $pfx = $parentTag[$k1] ?? 0;
                    $g1['cost'] = (int) ($g1['cost'] ?? 0) + $pfx;
                } else {
                    $g1['cost'] = (int) ($g1['cost'] ?? 0) + $subSum;
                }
            }
        }
        unset($g1);

        if ($hasTagGrouping) {
            $result['cost'] = (int) ($result['cost'] ?? 0) + $maps['total_fixed_cents'];
        } else {
            $sum = 0;
            foreach ($result['grouped_data'] as $g1) {
                $sum += (int) ($g1['cost'] ?? 0);
            }
            $result['cost'] = $sum;
        }
    }

    /**
     * @param  Builder<TimeEntry>  $timeEntriesQuery
     * @return array{
     *       grouped_type: string|null,
     *       grouped_data: null|array<array{
     *           key: string|null,
     *           description: string|null,
     *           color: string|null,
     *           seconds: int,
     *           cost: int|null,
     *           grouped_type: string|null,
     *           grouped_data: null|array<array{
     *               key: string|null,
     *               description: string|null,
     *               color: string|null,
     *               seconds: int,
     *               cost: int|null,
     *               grouped_type: null,
     *               grouped_data: null
     *           }>
     *       }>,
     *       seconds: int,
     *       cost: int|null
     * }
     */
    public function getAggregatedTimeEntriesWithDescriptions(Builder $timeEntriesQuery, ?TimeEntryAggregationType $group1Type, ?TimeEntryAggregationType $group2Type, string $timezone, Weekday $startOfWeek, bool $fillGapsInTimeGroups, ?Carbon $start, ?Carbon $end, bool $showBillableRate, ?TimeEntryRoundingType $roundingType, ?int $roundingMinutes): array
    {
        $aggregatedTimeEntries = $this->getAggregatedTimeEntries($timeEntriesQuery, $group1Type, $group2Type, $timezone, $startOfWeek, $fillGapsInTimeGroups, $start, $end, $showBillableRate, $roundingType, $roundingMinutes);

        $keysGroup1 = [];
        $keysGroup2 = [];

        if ($aggregatedTimeEntries['grouped_data'] !== null) {
            foreach ($aggregatedTimeEntries['grouped_data'] as $group1) {
                $keysGroup1[] = $group1['key'];
                if ($group1['grouped_data'] !== null) {
                    foreach ($group1['grouped_data'] as $group2) {
                        $keysGroup2[] = $group2['key'];
                    }
                }
            }
        }

        $descriptionMapGroup1 = $group1Type !== null ? $this->loadDescriptorsMap($keysGroup1, $group1Type) : [];
        $descriptionMapGroup2 = $group2Type !== null ? $this->loadDescriptorsMap($keysGroup2, $group2Type) : [];

        if ($aggregatedTimeEntries['grouped_data'] !== null) {
            foreach ($aggregatedTimeEntries['grouped_data'] as $keyGroup1 => $group1) {
                $aggregatedTimeEntries['grouped_data'][$keyGroup1]['description'] = $group1['key'] !== null ? ($descriptionMapGroup1[$group1['key']]['description'] ?? null) : null;
                $aggregatedTimeEntries['grouped_data'][$keyGroup1]['color'] = $group1['key'] !== null ? ($descriptionMapGroup1[$group1['key']]['color'] ?? null) : null;
                if ($aggregatedTimeEntries['grouped_data'][$keyGroup1]['grouped_data'] !== null) {
                    foreach ($aggregatedTimeEntries['grouped_data'][$keyGroup1]['grouped_data'] as $keyGroup2 => $group2) {
                        $aggregatedTimeEntries['grouped_data'][$keyGroup1]['grouped_data'][$keyGroup2]['description'] = $group2['key'] !== null ? ($descriptionMapGroup2[$group2['key']]['description'] ?? null) : null;
                        $aggregatedTimeEntries['grouped_data'][$keyGroup1]['grouped_data'][$keyGroup2]['color'] = $group2['key'] !== null ? ($descriptionMapGroup2[$group2['key']]['color'] ?? null) : null;
                    }
                }
            }
        }

        /**
         * @var array{
         *        grouped_type: string|null,
         *        grouped_data: null|array<array{
         *            key: string|null,
         *            description: string|null,
         *            color: string|null,
         *            seconds: int,
         *            cost: int,
         *            grouped_type: string|null,
         *            grouped_data: null|array<array{
         *                key: string|null,
         *                description: string|null,
         *                color: string|null,
         *                seconds: int,
         *                cost: int,
         *                grouped_type: null,
         *                grouped_data: null
         *            }>
         *        }>,
         *        seconds: int,
         *        cost: int
         *  } $aggregatedTimeEntries
         */

        return $aggregatedTimeEntries;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, array{
     *     description: string,
     *     color: string|null
     * }>
     */
    private function loadDescriptorsMap(array $keys, TimeEntryAggregationType $type): array
    {
        $descriptorMap = [];
        if ($type === TimeEntryAggregationType::Client) {
            $clients = Client::query()
                ->whereIn('id', $keys)
                ->select('id', 'name')
                ->get();
            foreach ($clients as $client) {
                $descriptorMap[$client->id] = [
                    'description' => $client->name,
                    'color' => null,
                ];
            }
        } elseif ($type === TimeEntryAggregationType::User) {
            $users = User::query()
                ->whereIn('id', $keys)
                ->select('id', 'name')
                ->get();
            foreach ($users as $user) {
                $descriptorMap[$user->id] = [
                    'description' => $user->name,
                    'color' => null,
                ];
            }
        } elseif ($type === TimeEntryAggregationType::Project) {
            $projects = Project::query()
                ->whereIn('id', $keys)
                ->select('id', 'name', 'color')
                ->get();
            foreach ($projects as $project) {
                $descriptorMap[$project->id] = [
                    'description' => $project->name,
                    'color' => $project->color,
                ];
            }
        } elseif ($type === TimeEntryAggregationType::Task) {
            $tasks = Task::query()
                ->whereIn('id', $keys)
                ->select('id', 'name')
                ->get();
            foreach ($tasks as $task) {
                $descriptorMap[$task->id] = [
                    'description' => $task->name,
                    'color' => null,
                ];
            }
        } elseif ($type === TimeEntryAggregationType::Description) {
            foreach ($keys as $key) {
                $descriptorMap[$key] = [
                    'description' => $key,
                    'color' => null,
                ];
            }
        } elseif ($type === TimeEntryAggregationType::Billable) {
            foreach ($keys as $key) {
                $descriptorMap[$key] = [
                    'description' => $key === '0' ? 'Non-billable' : 'Billable',
                    'color' => null,
                ];
            }
        } elseif ($type === TimeEntryAggregationType::Tag) {
            $tags = Tag::query()
                ->whereIn('id', $keys)
                ->select('id', 'name')
                ->get();
            foreach ($tags as $tag) {
                $descriptorMap[$tag->id] = [
                    'description' => $tag->name,
                    'color' => null,
                ];
            }
        }

        return $descriptorMap;
    }

    /**
     * @param array<array{
     *            key: string|null,
     *            seconds: int,
     *            cost: int|null,
     *            grouped_type: string|null,
     *            grouped_data: null|array<array{
     *                key: string|null,
     *                seconds: int,
     *                cost: int|null,
     *                grouped_type: null|mixed,
     *                grouped_data: null|mixed
     *            }>
     *        }> $data
     * @return array<array{
     *            key: string|null,
     *            seconds: int,
     *            cost: int|null,
     *            grouped_type: string|null,
     *            grouped_data: null|array<array{
     *                key: string|null,
     *                seconds: int,
     *                cost: int|null,
     *                grouped_type: null|mixed,
     *                grouped_data: null|mixed
     *            }>
     *        }>
     */
    public function fillGapsInTimeGroups(array $data, TimeEntryAggregationType $groupType, ?TimeEntryAggregationType $subGroupType, string $timezone, Weekday $startOfWeek, Carbon $start, Carbon $end): array
    {
        $interval = $groupType->toInterval();
        if ($interval === null) {
            foreach ($data as $key => $item) {
                $data[$key]['grouped_data'] = $this->fillGapsInTimeGroups(
                    $item['grouped_data'],
                    $subGroupType,
                    null,
                    $timezone,
                    $startOfWeek,
                    $start,
                    $end
                );
            }

            return $data;
        } else {
            $format = match ($interval) {
                TimeEntryAggregationTypeInterval::Day, TimeEntryAggregationTypeInterval::Week => 'Y-m-d',
                TimeEntryAggregationTypeInterval::Month => 'Y-m',
                TimeEntryAggregationTypeInterval::Year => 'Y',
            };
            $slots = $this->timeSlotsBetween($start, $end, $timezone, $startOfWeek, $interval, $format);
            $foundEntries = [];
            $filledData = [];
            foreach ($slots as $slot) {
                $foundDataSet = null;
                foreach ($data as $item) {
                    if ($item['key'] === $slot) {
                        $foundDataSet = $item;
                        $foundEntries[] = $item['key'];
                        break;
                    }
                }
                if ($foundDataSet !== null) {
                    $filledData[] = [
                        'key' => $slot,
                        'seconds' => $foundDataSet['seconds'],
                        'cost' => $foundDataSet['cost'],
                        'grouped_type' => $subGroupType?->value,
                        'grouped_data' => $subGroupType === null
                            ? null
                            : $this->fillGapsInTimeGroups(
                                $foundDataSet['grouped_data'],
                                $subGroupType,
                                null,
                                $timezone,
                                $startOfWeek,
                                $start,
                                $end
                            ),
                    ];
                } else {
                    $filledData[] = [
                        'key' => $slot,
                        'seconds' => 0,
                        'cost' => 0,
                        'grouped_type' => $subGroupType?->value,
                        'grouped_data' => $subGroupType === null ? null : [],
                    ];
                }
            }

            if (count($foundEntries) !== count($data)) {
                foreach ($data as $item) {
                    if (! in_array($item['key'], $foundEntries, true)) {
                        Log::error('Problem with filling gaps in time groups', [
                            'item' => $item,
                        ]);
                    }
                }
            }

            return $filledData;
        }
    }

    private function getGroupByQuery(TimeEntryAggregationType $group, string $timezone, Weekday $startOfWeek): string
    {
        $timezoneShift = app(TimezoneService::class)->getShiftFromUtc(new CarbonTimeZone($timezone));
        if ($timezoneShift > 0) {
            $dateWithTimeZone = 'start + INTERVAL \''.$timezoneShift.' second\'';
        } elseif ($timezoneShift < 0) {
            $dateWithTimeZone = 'start - INTERVAL \''.abs($timezoneShift).' second\'';
        } else {
            $dateWithTimeZone = 'start';
        }
        $startOfWeek = Carbon::now()->setTimezone($timezone)->startOfWeek($startOfWeek->carbonWeekDay())->toDateTimeString();
        if ($group === TimeEntryAggregationType::Day) {
            return 'date('.$dateWithTimeZone.')';
        } elseif ($group === TimeEntryAggregationType::Week) {
            return "to_char(date_bin('7 days', ".$dateWithTimeZone.", timestamp '".$startOfWeek."'), 'YYYY-MM-DD')";
        } elseif ($group === TimeEntryAggregationType::Month) {
            return 'to_char('.$dateWithTimeZone.', \'YYYY-MM\')';
        } elseif ($group === TimeEntryAggregationType::Year) {
            return 'to_char('.$dateWithTimeZone.', \'YYYY\')';
        } elseif ($group === TimeEntryAggregationType::User) {
            return 'user_id';
        } elseif ($group === TimeEntryAggregationType::Project) {
            return 'project_id';
        } elseif ($group === TimeEntryAggregationType::Task) {
            return 'task_id';
        } elseif ($group === TimeEntryAggregationType::Client) {
            return 'client_id';
        } elseif ($group === TimeEntryAggregationType::Billable) {
            return 'billable';
        } elseif ($group === TimeEntryAggregationType::Description) {
            return 'description';
        } elseif ($group === TimeEntryAggregationType::Tag) {
            return 'tag';
        }
    }

    /**
     * @return Collection<int, string>
     */
    public function timeSlotsBetween(Carbon $start, Carbon $end, string $timezone, Weekday $startOfWeek, TimeEntryAggregationTypeInterval $interval, string $format): Collection
    {
        if ($start->gt($end)) {
            throw new \InvalidArgumentException('Start date must be before end date');
        }
        $slots = new Collection;
        $current = $start->copy()->timezone($timezone);
        if ($interval === TimeEntryAggregationTypeInterval::Day) {
            $current->startOfDay();
        } elseif ($interval === TimeEntryAggregationTypeInterval::Week) {
            $current->startOfWeek($startOfWeek->carbonWeekDay());
        } elseif ($interval === TimeEntryAggregationTypeInterval::Month) {
            $current->startOfMonth();
        } elseif ($interval === TimeEntryAggregationTypeInterval::Year) {
            $current->startOfYear();
        } else {
            throw new \InvalidArgumentException('Invalid interval');
        }

        while ($current->lt($end)) {
            $slots->push($current->format($format));
            if ($interval === TimeEntryAggregationTypeInterval::Day) {
                $current->addDay();
            } elseif ($interval === TimeEntryAggregationTypeInterval::Week) {
                $current->addWeek();
            } elseif ($interval === TimeEntryAggregationTypeInterval::Month) {
                $current->addMonth();
            } elseif ($interval === TimeEntryAggregationTypeInterval::Year) {
                $current->addYear();
            }
        }

        return $slots;
    }

    /**
     * Hourly billable amounts only (entries with billable_rate), summed per billing currency for the filtered set.
     * Does not include fixed-contract allocations merged into aggregated row {@see mergeFixedProjectCostsIntoAggregatedResult}.
     *
     * @param  Builder<TimeEntry>  $filteredTimeEntriesQuery
     * @return list<array{currency_code: string, minor_units: int}>
     */
    public function hourlyBillableMinorUnitsByBillCurrencyForFilter(
        Builder $filteredTimeEntriesQuery,
        ?TimeEntryRoundingType $roundingType,
        ?int $roundingMinutes,
    ): array {
        $table = $filteredTimeEntriesQuery->getModel()->getTable();
        $startRaw = $this->timeEntryService->getStartSelectRawForRounding($roundingType, $roundingMinutes);
        $endRaw = $this->timeEntryService->getEndSelectRawForRounding($roundingType, $roundingMinutes);

        /** @var Collection<int, object{bill_currency: string, aggregate_amount: float|int|string}> $rows */
        $rows = $filteredTimeEntriesQuery->clone()
            ->join('organizations', "{$table}.organization_id", '=', 'organizations.id')
            ->leftJoin('projects', "{$table}.project_id", '=', 'projects.id')
            ->where("{$table}.billable", '=', true)
            ->whereNotNull("{$table}.billable_rate")
            ->selectRaw(
                'COALESCE(projects.currency, organizations.currency) as bill_currency, '
                .'round(sum(extract(epoch from ('.$endRaw.' - '.$startRaw.')) * ('.$table.'.billable_rate::float/60/60))) as aggregate_amount'
            )
            ->groupBy(DB::raw('COALESCE(projects.currency, organizations.currency)'))
            ->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            $code = (string) $row->bill_currency;
            $byCurrency[$code] = ($byCurrency[$code] ?? 0) + (int) $row->aggregate_amount;
        }
        ksort($byCurrency);

        /** @var list<array{currency_code: string, minor_units: int}> $out */
        $out = array_values(array_map(
            static fn (string $c, int $v): array => ['currency_code' => $c, 'minor_units' => $v],
            array_keys($byCurrency),
            array_values($byCurrency),
        ));

        return $out;
    }

    /**
     * Adds per-currency hourly billable totals and `currency_code` on rows when the primary group is project.
     *
     * @param  array<string, mixed>  $aggregatedPayload
     */
    public function augmentAggregateResultWithBillableCurrencyMetadata(
        array &$aggregatedPayload,
        ?Builder $filteredTimeEntriesQueryWithoutTagExpansion,
        bool $showBillableRate,
        ?TimeEntryRoundingType $roundingType,
        ?int $roundingMinutes,
    ): void {
        if ($showBillableRate && $filteredTimeEntriesQueryWithoutTagExpansion !== null) {
            $aggregatedPayload['billable_totals_by_currency'] = $this->hourlyBillableMinorUnitsByBillCurrencyForFilter(
                $filteredTimeEntriesQueryWithoutTagExpansion,
                $roundingType,
                $roundingMinutes,
            );
        } else {
            $aggregatedPayload['billable_totals_by_currency'] = null;
        }
        $this->attachCurrencyCodeToProjectGroupedRows($aggregatedPayload);
    }

    /**
     * @param  array<string, mixed>  $aggregatedPayload
     */
    private function attachCurrencyCodeToProjectGroupedRows(array &$aggregatedPayload): void
    {
        if (($aggregatedPayload['grouped_type'] ?? null) !== TimeEntryAggregationType::Project->value) {
            return;
        }
        /** @var mixed $gdRaw */
        $gdRaw = $aggregatedPayload['grouped_data'] ?? null;
        if (! is_array($gdRaw)) {
            return;
        }
        $ids = [];
        foreach ($gdRaw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $k = $row['key'] ?? null;
            if (is_string($k) && $k !== '') {
                $ids[] = $k;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }

        /** @var array<string, string> $map */
        $map = Project::query()->whereIn('id', $ids)->pluck('currency', 'id')->all();

        foreach ($gdRaw as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $k = $row['key'] ?? null;
            if (! is_string($k) || $k === '' || ! isset($map[$k])) {
                continue;
            }
            $code = $map[$k];
            /** @var array<string, mixed> $mutable */
            $mutable = $aggregatedPayload['grouped_data'][$i];
            $mutable['currency_code'] = $code;
            $sub = $mutable['grouped_data'] ?? null;
            if (is_array($sub)) {
                foreach ($sub as $j => $subRow) {
                    if (! is_array($subRow)) {
                        continue;
                    }
                    $subRow['currency_code'] = $code;
                    $mutable['grouped_data'][$j] = $subRow;
                }
            }
            $aggregatedPayload['grouped_data'][$i] = $mutable;
        }
    }
}
