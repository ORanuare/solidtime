<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\ProjectBillingType;
use App\Models\Member;
use App\Models\MemberCurrencyRate;
use App\Models\Organization;
use App\Models\OrganizationCurrency;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;

class BillableRateService
{
    public function updateTimeEntriesBillableRateForProjectMember(ProjectMember $projectMember): void
    {
        if ($projectMember->project->billing_type === ProjectBillingType::Fixed) {
            TimeEntry::query()
                ->where('billable', '=', true)
                ->where('member_id', '=', $projectMember->member_id)
                ->where('project_id', '=', $projectMember->project_id)
                ->update(['billable_rate' => null]);

            return;
        }
        TimeEntry::query()
            ->where('billable', '=', true)
            ->where('member_id', '=', $projectMember->member_id)
            ->where('project_id', '=', $projectMember->project_id)
            ->update(['billable_rate' => $projectMember->billable_rate]);
    }

    public function updateTimeEntriesBillableRateForProject(Project $project): void
    {
        if ($project->billing_type === ProjectBillingType::Fixed) {
            TimeEntry::query()
                ->where('billable', '=', true)
                ->where('organization_id', '=', $project->organization_id)
                ->whereBelongsTo($project, 'project')
                ->update(['billable_rate' => null]);

            return;
        }
        TimeEntry::query()
            ->where('billable', '=', true)
            ->where('organization_id', '=', $project->organization_id)
            ->whereBelongsTo($project, 'project')
            ->whereDoesntHave('member', function (Builder $query) use ($project): void {
                /** @var Builder<Member> $query */
                $query->whereHas('projectMembers', function (Builder $query) use ($project): void {
                    /** @var Builder<ProjectMember> $query */
                    $query->whereBelongsTo($project, 'project')
                        ->whereNotNull('billable_rate');
                });
            })
            ->update(['billable_rate' => $project->billable_rate]);
    }

    /**
     * @param  list<string>|null  $onlyCurrencies  When set, only refresh entries whose billable context uses one of these ISO codes
     */
    public function updateTimeEntriesBillableRateForMember(Member $member, ?array $onlyCurrencies = null): void
    {
        $query = TimeEntry::query()
            ->where('billable', '=', true)
            ->where('organization_id', '=', $member->organization_id)
            ->where('member_id', '=', $member->getKey())
            ->whereDoesntHave('project', function (Builder $builder): void {
                $builder->where('billing_type', '=', ProjectBillingType::Fixed->value);
            })
            ->whereDoesntHave('project', function (Builder $builder) use ($member): void {
                /** @var Builder<Project> $builder */
                $builder->whereNotNull('billable_rate')
                    ->orWhereHas('members', function (Builder $builder) use ($member): void {
                        /** @var Builder<ProjectMember> $builder */
                        $builder->whereNotNull('billable_rate')
                            ->where('member_id', '=', $member->getKey());
                    });
            });

        if ($onlyCurrencies !== null && $onlyCurrencies !== []) {
            $organization = Organization::query()->find($member->organization_id);
            $primaryCurrency = $organization?->currency ?? '';
            $query->where(function (Builder $outer) use ($onlyCurrencies, $primaryCurrency): void {
                foreach ($onlyCurrencies as $code) {
                    $outer->orWhere(function (Builder $w) use ($code, $primaryCurrency): void {
                        $w->where(function (Builder $inner) use ($code, $primaryCurrency): void {
                            $inner->whereHas('project', function (Builder $p) use ($code): void {
                                $p->where('currency', '=', $code);
                            });
                            if ($primaryCurrency === $code) {
                                $inner->orWhereNull('project_id');
                            }
                        });
                    });
                }
            });
        }

        $query->chunkById(200, function ($entries) use ($member): void {
            /** @var \Illuminate\Support\Collection<int, TimeEntry> $entries */
            foreach ($entries as $timeEntry) {
                /** @var TimeEntry $timeEntry */
                $rate = $this->getBillableRateForTimeEntry($timeEntry);
                TimeEntry::query()->whereKey($timeEntry->getKey())->update(['billable_rate' => $rate]);
            }
        });
    }

    /**
     * @param  list<string>|null  $onlyCurrencies
     */
    public function updateTimeEntriesBillableRateForOrganization(Organization $organization, ?array $onlyCurrencies = null): void
    {
        $query = TimeEntry::query()
            ->where('billable', '=', true)
            ->where('organization_id', '=', $organization->getKey())
            ->whereDoesntHave('project', function (Builder $builder): void {
                $builder->where('billing_type', '=', ProjectBillingType::Fixed->value);
            })
            ->whereDoesntHave('project', function (Builder $builder): void {
                /** @var Builder<Project> $builder */
                $builder->whereNotNull('billable_rate')
                    ->orWhereHas('members', function (Builder $builder): void {
                        /** @var Builder<ProjectMember> $builder */
                        $builder->whereNotNull('billable_rate')
                            ->whereRaw('member_id = time_entries.member_id');
                    });
            });

        if ($onlyCurrencies !== null && $onlyCurrencies !== []) {
            $primaryCurrency = $organization->currency;
            $query->where(function (Builder $outer) use ($onlyCurrencies, $primaryCurrency): void {
                foreach ($onlyCurrencies as $code) {
                    $outer->orWhere(function (Builder $w) use ($code, $primaryCurrency): void {
                        $w->where(function (Builder $inner) use ($code, $primaryCurrency): void {
                            $inner->whereHas('project', function (Builder $p) use ($code): void {
                                $p->where('currency', '=', $code);
                            });
                            if ($primaryCurrency === $code) {
                                $inner->orWhereNull('project_id');
                            }
                        });
                    });
                }
            });
        }

        $query->chunkById(200, function ($entries): void {
            foreach ($entries as $timeEntry) {
                /** @var TimeEntry $timeEntry */
                $rate = $this->getBillableRateForTimeEntry($timeEntry);
                TimeEntry::query()->whereKey($timeEntry->getKey())->update(['billable_rate' => $rate]);
            }
        });
    }

    public function getBillableRateForTimeEntryWithGivenRelations(TimeEntry $timeEntry, ?ProjectMember $projectMember, ?Project $project, ?Member $member, ?Organization $organization): ?int
    {
        if (! $timeEntry->billable) {
            return null;
        }
        if ($project !== null && $project->billing_type === ProjectBillingType::Fixed) {
            return null;
        }
        $currency = $this->resolveCurrencyForTimeEntry($project, $organization);
        if ($projectMember !== null && $projectMember->billable_rate !== null) {
            return $projectMember->billable_rate;
        }
        if ($project !== null && $project->billable_rate !== null) {
            return $project->billable_rate;
        }
        if ($member !== null) {
            $memberRate = $this->getMemberBillableRateForCurrency($member, $currency, $organization);
            if ($memberRate !== null) {
                return $memberRate;
            }
        }
        if ($organization !== null) {
            $orgRate = $this->getOrganizationDefaultBillableRateForCurrency($organization, $currency);
            if ($orgRate !== null) {
                return $orgRate;
            }
        }

        return null;
    }

    public function getBillableRateForTimeEntry(TimeEntry $timeEntry): ?int
    {
        if (! $timeEntry->billable) {
            return null;
        }
        if ($timeEntry->project_id !== null) {
            /** @var Project|null $project */
            $project = Project::find($timeEntry->project_id);
            if ($project !== null && $project->billing_type === ProjectBillingType::Fixed) {
                return null;
            }
            /** @var ProjectMember|null $projectMember */
            $projectMember = ProjectMember::query()
                ->where('user_id', '=', $timeEntry->user_id)
                ->where('project_id', '=', $timeEntry->project_id)
                ->first();

            /** @var Member|null $member */
            $member = Member::query()
                ->where('user_id', '=', $timeEntry->user_id)
                ->where('organization_id', '=', $timeEntry->organization_id)
                ->first();
            /** @var Organization|null $organization */
            $organization = Organization::query()
                ->where('id', '=', $timeEntry->organization_id)
                ->first();

            return $this->getBillableRateForTimeEntryWithGivenRelations($timeEntry, $projectMember, $project, $member, $organization);
        }

        /** @var Member|null $member */
        $member = Member::query()
            ->where('user_id', '=', $timeEntry->user_id)
            ->where('organization_id', '=', $timeEntry->organization_id)
            ->first();
        /** @var Organization|null $organization */
        $organization = Organization::query()
            ->where('id', '=', $timeEntry->organization_id)
            ->first();

        return $this->getBillableRateForTimeEntryWithGivenRelations($timeEntry, null, null, $member, $organization);
    }

    public function resolveCurrencyForTimeEntry(?Project $project, ?Organization $organization): string
    {
        if ($project !== null) {
            return $project->currency;
        }
        if ($organization !== null) {
            return $organization->currency;
        }

        return config('app.localization.default_currency');
    }

    public function getMemberBillableRateForCurrency(Member $member, string $currencyCode, ?Organization $organizationContext = null): ?int
    {
        $organization = $organizationContext;
        if ($organization === null && $member->relationLoaded('organization')) {
            $organization = $member->getRelation('organization');
        }
        if ($organization === null) {
            $organization = Organization::query()->find($member->organization_id);
        }

        if ($organization !== null
            && $organization->currency === $currencyCode
            && $member->billable_rate !== null) {
            return $member->billable_rate;
        }

        $row = MemberCurrencyRate::query()
            ->where('member_id', '=', $member->getKey())
            ->where('currency_code', '=', $currencyCode)
            ->first();
        if ($row !== null && $row->billable_rate !== null) {
            return $row->billable_rate;
        }

        return null;
    }

    public function getOrganizationDefaultBillableRateForCurrency(Organization $organization, string $currencyCode): ?int
    {
        if ($organization->currency === $currencyCode && $organization->billable_rate !== null) {
            return $organization->billable_rate;
        }

        $row = OrganizationCurrency::query()
            ->where('organization_id', '=', $organization->getKey())
            ->where('currency_code', '=', $currencyCode)
            ->first();
        if ($row !== null && $row->default_billable_rate !== null) {
            return $row->default_billable_rate;
        }

        return null;
    }

    public function refreshBillableRatesForAllProjectTimeEntries(Project $project): void
    {
        TimeEntry::query()
            ->where('billable', '=', true)
            ->whereBelongsTo($project, 'project')
            ->chunkById(200, function ($entries): void {
                foreach ($entries as $timeEntry) {
                    /** @var TimeEntry $timeEntry */
                    $rate = $this->getBillableRateForTimeEntry($timeEntry);
                    TimeEntry::query()->whereKey($timeEntry->getKey())->update(['billable_rate' => $rate]);
                }
            });
    }
}
