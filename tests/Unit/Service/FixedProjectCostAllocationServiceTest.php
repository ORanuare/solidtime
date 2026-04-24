<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Enums\ProjectBillingType;
use App\Enums\TimeEntryAggregationType;
use App\Enums\Weekday;
use App\Models\Client;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Service\FixedProjectCostAllocationService;
use App\Service\TimeEntryFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(FixedProjectCostAllocationService::class)]
class FixedProjectCostAllocationServiceTest extends TestCaseWithDatabase
{
    use RefreshDatabase;

    private FixedProjectCostAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FixedProjectCostAllocationService::class);
    }

    public function test_fixed_fee_splits_across_two_users_by_billable_seconds(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->forOrganization($organization)->create([
            'billing_type' => ProjectBillingType::Fixed,
            'fixed_price' => 10_000,
            'is_billable' => true,
        ]);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $member1 = Member::factory()->forUser($user1)->forOrganization($organization)->create();
        $member2 = Member::factory()->forUser($user2)->forOrganization($organization)->create();
        $start = Carbon::parse('2024-06-01 12:00:00', 'UTC');
        TimeEntry::factory()->startWithDuration($start, 3600)->forMember($member1)->forProject($project)->forOrganization($organization)->create([
            'billable' => true,
            'billable_rate' => null,
        ]);
        TimeEntry::factory()->startWithDuration($start, 3600)->forMember($member2)->forProject($project)->forOrganization($organization)->create([
            'billable' => true,
            'billable_rate' => null,
        ]);

        $query = TimeEntry::query()->whereBelongsTo($organization, 'organization');
        $maps = $this->service->computeFixedAllocationMaps(
            $query,
            TimeEntryAggregationType::User,
            null,
            'UTC',
            Weekday::Monday,
            null,
            null,
        );

        $this->assertSame(10_000, $maps['total_fixed_cents']);
        $k1 = $user1->getKey()."\x1e";
        $k2 = $user2->getKey()."\x1e";
        $this->assertSame(10_000, ($maps['leaf'][$k1] ?? 0) + ($maps['leaf'][$k2] ?? 0));
        $this->assertContains($maps['leaf'][$k1] ?? 0, [5000]);
        $this->assertContains($maps['leaf'][$k2] ?? 0, [5000]);
    }

    public function test_two_fixed_projects_under_same_client_sum_independently(): void
    {
        $organization = Organization::factory()->create();
        $projectA = Project::factory()->forOrganization($organization)->create([
            'billing_type' => ProjectBillingType::Fixed,
            'fixed_price' => 3000,
            'is_billable' => true,
        ]);
        $projectB = Project::factory()->forOrganization($organization)->create([
            'billing_type' => ProjectBillingType::Fixed,
            'fixed_price' => 7000,
            'is_billable' => true,
        ]);
        $user = User::factory()->create();
        $member = Member::factory()->forUser($user)->forOrganization($organization)->create();
        $start = Carbon::parse('2024-06-01 12:00:00', 'UTC');
        TimeEntry::factory()->startWithDuration($start, 1800)->forMember($member)->forProject($projectA)->forOrganization($organization)->create([
            'billable' => true,
            'billable_rate' => null,
            'client_id' => null,
        ]);
        TimeEntry::factory()->startWithDuration($start, 1800)->forMember($member)->forProject($projectB)->forOrganization($organization)->create([
            'billable' => true,
            'billable_rate' => null,
            'client_id' => null,
        ]);

        $query = TimeEntry::query()->whereBelongsTo($organization, 'organization');
        $maps = $this->service->computeFixedAllocationMaps(
            $query,
            TimeEntryAggregationType::Client,
            TimeEntryAggregationType::Project,
            'UTC',
            Weekday::Monday,
            null,
            null,
        );

        $this->assertSame(10_000, $maps['total_fixed_cents']);
        $sumLeaves = array_sum($maps['leaf']);
        $this->assertSame(10_000, $sumLeaves);
    }

    public function test_fixed_allocation_with_client_filter_does_not_produce_ambiguous_client_id_sql(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $project = Project::factory()->forOrganization($organization)->create([
            'client_id' => $client->id,
            'billing_type' => ProjectBillingType::Fixed,
            'fixed_price' => 10_000,
            'is_billable' => true,
        ]);
        $user = User::factory()->create();
        $member = Member::factory()->forUser($user)->forOrganization($organization)->create();
        $start = Carbon::parse('2024-06-01 12:00:00', 'UTC');
        TimeEntry::factory()->startWithDuration($start, 3600)->forMember($member)->forProject($project)->forOrganization($organization)->create([
            'billable' => true,
            'billable_rate' => null,
            'client_id' => $client->id,
        ]);

        $query = TimeEntry::query()->whereBelongsTo($organization, 'organization');
        (new TimeEntryFilter($query))->addClientIdsFilter([$client->id])->get();

        $maps = $this->service->computeFixedAllocationMaps(
            $query,
            null,
            null,
            'UTC',
            Weekday::Monday,
            null,
            null,
        );

        $this->assertSame(10_000, $maps['total_fixed_cents']);
    }
}
