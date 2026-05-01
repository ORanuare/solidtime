<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\NoteVisibility;
use App\Http\Controllers\Api\V1\CalendarEventController;
use App\Models\CalendarEvent;
use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\UsesClass;

#[UsesClass(CalendarEventController::class)]
class CalendarEventEndpointTest extends ApiEndpointTestAbstract
{
    private function windowQuery(string $start, string $end): array
    {
        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    public function test_index_fails_without_calendar_events_view_permission(): void
    {
        $data = $this->createUserWithPermission();
        Passport::actingAs($data->user);

        $response = $this->getJson(route('api.v1.calendar-events.index', [
            $data->organization->getKey(),
            ...$this->windowQuery('2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z'),
        ]));

        $response->assertForbidden();
    }

    public function test_store_creates_workspace_shared_event(): void
    {
        $data = $this->createUserWithPermission([
            'calendar-events:create',
            'calendar-events:view',
        ]);
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.calendar-events.store', [$data->organization->getKey()]), [
            'title' => 'Team sync',
            'description' => null,
            'starts_at' => '2026-05-10T14:00:00Z',
            'ends_at' => '2026-05-10T15:00:00Z',
            'all_day' => false,
            'visibility' => NoteVisibility::Shared->value,
        ]);

        $response->assertStatus(201);
        $response->assertJson(fn (AssertableJson $json) => $json
            ->has('data')
            ->where('data.title', 'Team sync')
            ->where('data.visibility', 'shared')
            ->where('data.eventable_type', null));

        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Team sync',
            'organization_id' => $data->organization->getKey(),
            'user_id' => $data->user->getKey(),
        ]);
    }

    public function test_index_filters_by_date_window(): void
    {
        $data = $this->createUserWithPermission([
            'calendar-events:view',
            'calendar-events:create',
        ]);
        Passport::actingAs($data->user);

        $inside = CalendarEvent::factory()
            ->forOrganization($data->organization)
            ->author($data->user)
            ->shared()
            ->create([
                'title' => 'Inside',
                'starts_at' => '2026-05-10 10:00:00',
                'ends_at' => '2026-05-10 11:00:00',
                'all_day' => false,
            ]);

        CalendarEvent::factory()
            ->forOrganization($data->organization)
            ->author($data->user)
            ->shared()
            ->create([
                'title' => 'Outside',
                'starts_at' => '2026-06-01 10:00:00',
                'ends_at' => '2026-06-01 11:00:00',
                'all_day' => false,
            ]);

        $response = $this->getJson(route('api.v1.calendar-events.index', [
            $data->organization->getKey(),
            ...$this->windowQuery('2026-05-01T00:00:00Z', '2026-05-20T00:00:00Z'),
        ]));

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$inside->getKey()], $ids);
    }

    public function test_all_day_event_overlaps_window(): void
    {
        $data = $this->createUserWithPermission([
            'calendar-events:view',
            'calendar-events:create',
        ]);
        Passport::actingAs($data->user);

        $event = CalendarEvent::factory()
            ->forOrganization($data->organization)
            ->author($data->user)
            ->shared()
            ->allDay()
            ->create([
                'title' => 'Sprint',
                'starts_at' => '2026-05-10 00:00:00',
                'ends_at' => '2026-05-12 00:00:00',
                'all_day' => true,
            ]);

        $response = $this->getJson(route('api.v1.calendar-events.index', [
            $data->organization->getKey(),
            ...$this->windowQuery('2026-05-11T00:00:00Z', '2026-05-11T23:59:59Z'),
        ]));

        $response->assertStatus(200);
        $this->assertContains($event->getKey(), collect($response->json('data'))->pluck('id')->all());
    }

    public function test_index_with_project_id_includes_workspace_events(): void
    {
        $data = $this->createUserWithPermission([
            'calendar-events:view',
            'calendar-events:create',
            'projects:view:all',
            'tasks:view:all',
        ]);
        $project = Project::factory()->forOrganization($data->organization)->create();
        $task = Task::factory()->forProject($project)->forOrganization($data->organization)->create();

        $workspace = CalendarEvent::factory()
            ->forOrganization($data->organization)
            ->author($data->user)
            ->shared()
            ->create([
                'title' => 'Ws',
                'starts_at' => '2026-05-10 10:00:00',
                'ends_at' => '2026-05-10 11:00:00',
            ]);

        $onProject = CalendarEvent::factory()
            ->forOrganization($data->organization)
            ->author($data->user)
            ->shared()
            ->create([
                'title' => 'Proj',
                'starts_at' => '2026-05-10 10:00:00',
                'ends_at' => '2026-05-10 11:00:00',
            ]);
        $onProject->eventable()->associate($project);
        $onProject->save();

        $onTask = CalendarEvent::factory()
            ->forOrganization($data->organization)
            ->author($data->user)
            ->shared()
            ->create([
                'title' => 'TaskEv',
                'starts_at' => '2026-05-10 10:00:00',
                'ends_at' => '2026-05-10 11:00:00',
            ]);
        $onTask->eventable()->associate($task);
        $onTask->save();

        Passport::actingAs($data->user);
        $response = $this->getJson(route('api.v1.calendar-events.index', [
            $data->organization->getKey(),
            ...$this->windowQuery('2026-05-01T00:00:00Z', '2026-05-30T00:00:00Z'),
            'project_id' => $project->getKey(),
        ]));

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing(
            [$workspace->getKey(), $onProject->getKey(), $onTask->getKey()],
            $ids
        );
    }

    public function test_private_event_visible_only_to_creator(): void
    {
        $ownerData = $this->createUserWithPermission([
            'calendar-events:view',
            'calendar-events:create',
            'projects:view:all',
            'tasks:view:all',
        ]);

        $otherUser = User::factory()->create();
        Member::factory()->forUser($otherUser)->forOrganization($ownerData->organization)->create([
            'role' => $ownerData->member->role,
        ]);
        $otherUser->currentOrganization()->associate($ownerData->organization);
        $otherUser->save();

        $privateEv = CalendarEvent::factory()
            ->forOrganization($ownerData->organization)
            ->author($ownerData->user)
            ->private()
            ->create([
                'title' => 'Secret',
                'starts_at' => '2026-05-10 10:00:00',
                'ends_at' => '2026-05-10 11:00:00',
            ]);

        Passport::actingAs($otherUser);
        $response = $this->getJson(route('api.v1.calendar-events.index', [
            'organization' => $ownerData->organization->getKey(),
            ...$this->windowQuery('2026-05-01T00:00:00Z', '2026-05-30T00:00:00Z'),
        ]));
        $response->assertStatus(200);
        $this->assertNotContains($privateEv->getKey(), collect($response->json('data'))->pluck('id')->all());

        Passport::actingAs($ownerData->user);
        $response2 = $this->getJson(route('api.v1.calendar-events.index', [
            'organization' => $ownerData->organization->getKey(),
            ...$this->windowQuery('2026-05-01T00:00:00Z', '2026-05-30T00:00:00Z'),
        ]));
        $response2->assertStatus(200);
        $this->assertContains($privateEv->getKey(), collect($response2->json('data'))->pluck('id')->all());
    }

    public function test_update_requires_author(): void
    {
        $data = $this->createUserWithPermission([
            'calendar-events:view',
            'calendar-events:create',
            'calendar-events:update',
        ]);
        $other = User::factory()->create();
        Member::factory()->forUser($other)->forOrganization($data->organization)->create([
            'role' => $data->member->role,
        ]);

        $event = CalendarEvent::factory()
            ->forOrganization($data->organization)
            ->author($other)
            ->shared()
            ->create([
                'title' => 'Not mine',
                'starts_at' => '2026-05-10 10:00:00',
                'ends_at' => '2026-05-10 11:00:00',
            ]);

        Passport::actingAs($data->user);
        $response = $this->putJson(route('api.v1.calendar-events.update', [
            $data->organization->getKey(),
            $event->getKey(),
        ]), [
            'title' => 'Hacked',
        ]);

        $response->assertForbidden();
    }

    public function test_author_can_update_and_delete(): void
    {
        $data = $this->createUserWithPermission([
            'calendar-events:view',
            'calendar-events:create',
            'calendar-events:update',
            'calendar-events:delete',
        ]);

        $event = CalendarEvent::factory()
            ->forOrganization($data->organization)
            ->author($data->user)
            ->shared()
            ->create([
                'title' => 'Mine',
                'starts_at' => '2026-05-10 10:00:00',
                'ends_at' => '2026-05-10 11:00:00',
            ]);

        Passport::actingAs($data->user);
        $upd = $this->putJson(route('api.v1.calendar-events.update', [
            $data->organization->getKey(),
            $event->getKey(),
        ]), [
            'title' => 'Updated',
        ]);
        $upd->assertStatus(200);
        $this->assertSame('Updated', $event->fresh()->title);

        $del = $this->deleteJson(route('api.v1.calendar-events.destroy', [
            $data->organization->getKey(),
            $event->getKey(),
        ]));
        $del->assertStatus(204);
        $this->assertDatabaseMissing('calendar_events', ['id' => $event->getKey()]);
    }

    public function test_store_fails_without_create_permission(): void
    {
        $data = $this->createUserWithPermission([
            'calendar-events:view',
        ]);
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.calendar-events.store', [$data->organization->getKey()]), [
            'title' => 'Nope',
            'starts_at' => '2026-05-10T14:00:00Z',
            'ends_at' => '2026-05-10T15:00:00Z',
            'all_day' => false,
            'visibility' => NoteVisibility::Shared->value,
        ]);

        $response->assertForbidden();
    }
}
