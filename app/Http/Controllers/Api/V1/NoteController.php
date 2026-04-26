<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\NoteVisibility;
use App\Http\Requests\V1\Note\NoteIndexRequest;
use App\Http\Requests\V1\Note\NoteStoreRequest;
use App\Http\Requests\V1\Note\NoteUpdateRequest;
use App\Http\Resources\V1\Note\NoteCollection;
use App\Http\Resources\V1\Note\NoteResource;
use App\Models\Note;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class NoteController extends Controller
{
    protected function checkPermission(Organization $organization, string $permission, ?Note $note = null): void
    {
        parent::checkPermission($organization, $permission);
        if ($note !== null && $note->organization_id !== $organization->id) {
            throw new AuthorizationException('Note does not belong to organization');
        }
    }

    /**
     * @return NoteCollection<NoteResource>
     *
     * @throws AuthorizationException
     *
     * @operationId getNotes
     */
    public function index(Organization $organization, NoteIndexRequest $request): NoteCollection
    {
        $this->checkPermission($organization, 'notes:view');
        $user = $this->user();

        $query = $this->visibleNotesQuery($organization, $user);

        if ($request->filled('task_id')) {
            $taskId = $request->input('task_id');
            $query->where(function (Builder $q) use ($taskId): void {
                $q->where(function (Builder $q2) use ($taskId): void {
                    $q2->where('notable_type', 'task')
                        ->where('notable_id', $taskId);
                })->orWhere(function (Builder $q2): void {
                    $this->scopeWorkspaceNotables($q2);
                });
            });
        } elseif ($request->filled('project_id')) {
            $projectId = $request->input('project_id');
            $query->where(function (Builder $q) use ($projectId): void {
                $q->where(function (Builder $q2) use ($projectId): void {
                    $q2->where(function (Builder $q3) use ($projectId): void {
                        $q3->where('notable_type', 'project')
                            ->where('notable_id', $projectId);
                    })->orWhere(function (Builder $q3) use ($projectId): void {
                        $q3->where('notable_type', 'task')
                            ->whereHasMorph('notable', [Task::class], function (Builder $q4) use ($projectId): void {
                                $q4->where('project_id', $projectId);
                            });
                    });
                })->orWhere(function (Builder $q2): void {
                    $this->scopeWorkspaceNotables($q2);
                });
            });
        }

        if ($request->filled('visibility')) {
            $query->where('visibility', $request->input('visibility'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('body', 'like', '%'.$search.'%');
        }

        $filterArchived = $request->getFilterArchived();
        if ($filterArchived === 'true') {
            $query->whereNotNull('archived_at');
        } elseif ($filterArchived === 'false') {
            $query->whereNull('archived_at');
        }

        $notes = $query
            ->with(['user', 'notable'])
            ->orderBy('created_at', 'desc')
            ->paginate(config('app.pagination_per_page_default'));

        return new NoteCollection($notes);
    }

    /**
     * @throws AuthorizationException
     *
     * @operationId createNote
     */
    public function store(Organization $organization, NoteStoreRequest $request): JsonResource
    {
        $this->checkPermission($organization, 'notes:create');
        $user = $this->user();

        $task = null;
        $project = null;
        if ($request->filled('task_id')) {
            /** @var Task $task */
            $task = Task::query()
                ->whereBelongsTo($organization, 'organization')
                ->findOrFail($request->input('task_id'));
            $this->assertUserCanAccessProject($organization, $user, $task->project);
        } elseif ($request->filled('project_id')) {
            /** @var Project $project */
            $project = Project::query()
                ->whereBelongsTo($organization, 'organization')
                ->findOrFail($request->input('project_id'));
            $this->assertUserCanAccessProject($organization, $user, $project);
        }

        $note = new Note;
        $note->body = $request->input('body');
        $note->visibility = NoteVisibility::from($request->input('visibility'));
        $note->user()->associate($user);
        $note->organization()->associate($organization);
        if ($task !== null) {
            $note->notable()->associate($task);
        } elseif ($project !== null) {
            $note->notable()->associate($project);
        } else {
            $note->notable_type = null;
            $note->notable_id = null;
        }
        $note->save();
        $note->load(['user', 'notable']);

        return new NoteResource($note);
    }

    /**
     * @throws AuthorizationException
     *
     * @operationId updateNote
     */
    public function update(Organization $organization, Note $note, NoteUpdateRequest $request): JsonResource
    {
        $this->checkPermission($organization, 'notes:update', $note);
        $this->assertAuthor($note);

        if ($request->has('body')) {
            $note->body = $request->input('body');
        }
        if ($request->has('visibility')) {
            $note->visibility = NoteVisibility::from($request->input('visibility'));
        }
        if ($request->has('is_archived')) {
            $note->archived_at = $request->getIsArchived() ? Carbon::now() : null;
        }
        $note->save();
        $note->load(['user', 'notable']);

        return new NoteResource($note);
    }

    /**
     * @throws AuthorizationException
     *
     * @operationId deleteNote
     */
    public function destroy(Organization $organization, Note $note): JsonResponse
    {
        $this->checkPermission($organization, 'notes:delete', $note);
        $this->assertAuthor($note);
        $note->delete();

        return response()->json(null, 204);
    }

    /**
     * Workspace / org-level notes (not attached to a project or task).
     *
     * @param  Builder<Note>  $query
     */
    private function scopeWorkspaceNotables(Builder $query): void
    {
        $query->whereNull('notable_type')
            ->whereNull('notable_id');
    }

    /**
     * @param  Builder<Note>  $query
     * @return Builder<Note>
     */
    private function visibleNotesQuery(Organization $organization, User $user): Builder
    {
        $canViewAllProjects = $this->hasPermission($organization, 'projects:view:all');
        $canViewAllTasks = $this->hasPermission($organization, 'tasks:view:all');

        return Note::query()
            ->where('organization_id', $organization->id)
            ->where(function (Builder $q) use ($organization, $user, $canViewAllProjects, $canViewAllTasks): void {
                $q->where(function (Builder $q2) use ($user): void {
                    $q2->where('visibility', NoteVisibility::Private)
                        ->where('user_id', $user->id);
                })->orWhere(function (Builder $q2) use ($organization, $user, $canViewAllProjects, $canViewAllTasks): void {
                    $q2->where('visibility', NoteVisibility::Shared)
                        ->where(function (Builder $q3): void {
                            $q3->whereNull('notable_type')
                                ->whereNull('notable_id');
                        })->orWhere(function (Builder $q3) use ($organization, $user, $canViewAllProjects): void {
                            $q3->where('notable_type', 'project')
                                ->whereHasMorph('notable', [Project::class], function (Builder $q4) use ($organization, $user, $canViewAllProjects): void {
                                    $q4->where('organization_id', $organization->id);
                                    if (! $canViewAllProjects) {
                                        $q4->visibleByEmployee($user);
                                    }
                                });
                        })->orWhere(function (Builder $q3) use ($organization, $user, $canViewAllTasks): void {
                            $q3->where('notable_type', 'task')
                                ->whereHasMorph('notable', [Task::class], function (Builder $q4) use ($organization, $user, $canViewAllTasks): void {
                                    $q4->where('organization_id', $organization->id);
                                    if (! $canViewAllTasks) {
                                        $q4->visibleByEmployee($user);
                                    }
                                });
                        });
                });
            });
    }

    private function assertUserCanAccessProject(Organization $organization, User $user, Project $project): void
    {
        if ($project->organization_id !== $organization->id) {
            throw new AuthorizationException('Project does not belong to organization');
        }
        if ($this->hasPermission($organization, 'projects:view:all')) {
            return;
        }
        $canSee = Project::query()
            ->where('id', $project->id)
            ->whereBelongsTo($organization, 'organization')
            ->visibleByEmployee($user)
            ->exists();
        if (! $canSee) {
            throw new AuthorizationException('You do not have access to this project.');
        }
    }

    private function assertAuthor(Note $note): void
    {
        if ($note->user_id !== $this->user()->id) {
            throw new AuthorizationException;
        }
    }
}
