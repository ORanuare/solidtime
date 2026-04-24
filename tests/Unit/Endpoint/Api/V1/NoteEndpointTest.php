<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\NoteVisibility;
use App\Http\Controllers\Api\V1\NoteController;
use App\Models\Member;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\UsesClass;

#[UsesClass(NoteController::class)]
class NoteEndpointTest extends ApiEndpointTestAbstract
{
    public function test_index_fails_without_notes_view_permission(): void
    {
        $data = $this->createUserWithPermission();
        Passport::actingAs($data->user);

        $response = $this->getJson(route('api.v1.notes.index', [$data->organization->getKey()]));

        $response->assertForbidden();
    }

    public function test_store_creates_standalone_shared_note(): void
    {
        $data = $this->createUserWithPermission([
            'notes:create',
            'notes:view',
        ]);
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.notes.store', [$data->organization->getKey()]), [
            'title' => 'Inbox',
            'body' => 'Hello **world**',
            'visibility' => NoteVisibility::Shared->value,
        ]);

        $response->assertStatus(201);
        $response->assertJson(fn (AssertableJson $json) => $json
            ->has('data')
            ->where('data.title', 'Inbox')
            ->where('data.visibility', 'shared')
            ->where('data.notable_type', null));
        $this->assertDatabaseHas('notes', [
            'title' => 'Inbox',
            'organization_id' => $data->organization->getKey(),
            'user_id' => $data->user->getKey(),
        ]);
    }

    public function test_store_on_task_requires_project_access(): void
    {
        $data = $this->createUserWithPermission([
            'notes:create',
            'notes:view',
            'projects:view',
            'tasks:view',
        ]);
        $project = Project::factory()->forOrganization($data->organization)->create([
            'is_public' => false,
        ]);
        $task = Task::factory()->forProject($project)->forOrganization($data->organization)->create();
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.notes.store', [$data->organization->getKey()]), [
            'title' => 'Nope',
            'body' => 'x',
            'visibility' => NoteVisibility::Shared->value,
            'task_id' => $task->getKey(),
        ]);

        $response->assertForbidden();
    }

    public function test_index_lists_only_private_notes_for_author(): void
    {
        $data = $this->createUserWithPermission([
            'notes:view',
            'notes:create',
        ]);
        $bob = User::factory()->create();
        Member::factory()
            ->forUser($bob)
            ->forOrganization($data->organization)
            ->create([
                'role' => $data->member->role,
            ]);
        $bob->currentOrganization()->associate($data->organization);
        $bob->save();

        $private = Note::factory()
            ->forOrganization($data->organization)
            ->author($data->user)
            ->private()
            ->create([
                'title' => 'Alice private',
            ]);

        Note::factory()
            ->forOrganization($data->organization)
            ->author($bob)
            ->private()
            ->create([
                'title' => 'Bob private',
            ]);

        Passport::actingAs($data->user);
        $response = $this->getJson(route('api.v1.notes.index', [$data->organization->getKey()]));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $private->getKey());
    }

    public function test_update_fails_for_non_author(): void
    {
        $data = $this->createUserWithPermission([
            'notes:view',
            'notes:create',
            'notes:update',
        ]);
        $bob = User::factory()->create();
        Member::factory()
            ->forUser($bob)
            ->forOrganization($data->organization)
            ->create([
                'role' => $data->member->role,
            ]);
        $bob->currentOrganization()->associate($data->organization);
        $bob->save();

        $note = Note::factory()
            ->forOrganization($data->organization)
            ->author($bob)
            ->shared()
            ->create([
                'title' => 'Bob note',
                'body' => 'x',
            ]);

        Passport::actingAs($data->user);
        $response = $this->putJson(route('api.v1.notes.update', [$data->organization->getKey(), $note->getKey()]), [
            'title' => 'Hacked',
        ]);

        $response->assertForbidden();
    }
}
