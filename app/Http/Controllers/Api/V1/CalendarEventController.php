<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\NoteVisibility;
use App\Http\Requests\V1\CalendarEvent\CalendarEventIndexRequest;
use App\Http\Requests\V1\CalendarEvent\CalendarEventStoreRequest;
use App\Http\Requests\V1\CalendarEvent\CalendarEventUpdateRequest;
use App\Http\Resources\V1\CalendarEvent\CalendarEventCollection;
use App\Http\Resources\V1\CalendarEvent\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\CalendarEventAssignment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class CalendarEventController extends Controller
{
    protected function checkPermission(Organization $organization, string $permission, ?CalendarEvent $calendarEvent = null): void
    {
        parent::checkPermission($organization, $permission);
        if ($calendarEvent !== null && $calendarEvent->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('Calendar event does not belong to organization');
        }
    }

    /**
     * @return CalendarEventCollection<CalendarEventResource>
     *
     * @throws AuthorizationException
     *
     * @operationId getCalendarEvents
     */
    public function index(Organization $organization, CalendarEventIndexRequest $request): CalendarEventCollection
    {
        $this->checkPermission($organization, 'calendar-events:view');
        $user = $this->user();

        $windowStart = Carbon::parse($request->validated('start'));
        $windowEnd = Carbon::parse($request->validated('end'));

        $query = $this->visibleCalendarEventsQuery($organization, $user);

        $query->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart);

        if ($request->filled('task_id')) {
            $taskId = $request->input('task_id');
            $query->whereHas('assignments', function (Builder $q) use ($taskId): void {
                $q->where('assignable_type', 'task')
                    ->where('assignable_id', $taskId);
            });
        } elseif ($request->filled('project_id')) {
            $projectId = $request->input('project_id');
            $query->where(function (Builder $q) use ($projectId): void {
                $q->where(function (Builder $q2) use ($projectId): void {
                    $q2->whereHas('assignments', function (Builder $q3) use ($projectId): void {
                        $q3->where('assignable_type', 'project')
                            ->where('assignable_id', $projectId);
                    })->orWhereHas('assignments', function (Builder $q3) use ($projectId): void {
                        $q3->where('assignable_type', 'task')
                            ->whereHasMorph('assignable', [Task::class], function (Builder $q4) use ($projectId): void {
                                $q4->where('project_id', $projectId);
                            });
                    });
                })->orWhereDoesntHave('assignments');
            });
        }

        if ($request->filled('visibility')) {
            $query->where('visibility', $request->input('visibility'));
        }

        $events = $query
            ->with(['user', 'assignments.assignable'])
            ->orderBy('starts_at')
            ->paginate(config('app.pagination_per_page_default'));

        return new CalendarEventCollection($events);
    }

    /**
     * @throws AuthorizationException
     *
     * @operationId createCalendarEvent
     */
    public function store(Organization $organization, CalendarEventStoreRequest $request): JsonResource
    {
        $this->checkPermission($organization, 'calendar-events:create');
        $user = $this->user();

        $items = $this->assignmentItemsFromStoreRequest($request);
        $models = $this->resolveAssignmentModels($organization, $user, $items);

        $event = new CalendarEvent;
        $event->title = $request->input('title');
        $event->description = $request->input('description');
        $event->starts_at = Carbon::parse($request->input('starts_at'));
        $event->ends_at = Carbon::parse($request->input('ends_at'));
        $event->all_day = $request->boolean('all_day');
        $event->visibility = NoteVisibility::from($request->input('visibility'));
        $event->user()->associate($user);
        $event->organization()->associate($organization);
        $event->save();

        $this->syncAssignments($event, $models);
        $event->load(['user', 'assignments.assignable']);

        return new CalendarEventResource($event);
    }

    /**
     * @throws AuthorizationException
     *
     * @operationId updateCalendarEvent
     */
    public function update(Organization $organization, CalendarEvent $calendarEvent, CalendarEventUpdateRequest $request): JsonResource
    {
        $this->checkPermission($organization, 'calendar-events:update', $calendarEvent);
        $this->assertAuthor($calendarEvent);
        $user = $this->user();

        if ($request->has('title')) {
            $calendarEvent->title = $request->input('title');
        }
        if ($request->has('description')) {
            $calendarEvent->description = $request->input('description');
        }
        if ($request->has('starts_at')) {
            $calendarEvent->starts_at = Carbon::parse($request->input('starts_at'));
        }
        if ($request->has('ends_at')) {
            $calendarEvent->ends_at = Carbon::parse($request->input('ends_at'));
        }
        if ($request->has('all_day')) {
            $calendarEvent->all_day = $request->boolean('all_day');
        }
        if ($request->has('visibility')) {
            $calendarEvent->visibility = NoteVisibility::from($request->input('visibility'));
        }
        if ($request->boolean('reassign')) {
            $items = $this->assignmentItemsFromUpdateRequest($request);
            $models = $this->resolveAssignmentModels($organization, $user, $items);
            $this->syncAssignments($calendarEvent, $models);
        }
        $calendarEvent->save();
        $calendarEvent->load(['user', 'assignments.assignable']);

        return new CalendarEventResource($calendarEvent);
    }

    /**
     * @throws AuthorizationException
     *
     * @operationId deleteCalendarEvent
     */
    public function destroy(Organization $organization, CalendarEvent $calendarEvent): JsonResponse
    {
        $this->checkPermission($organization, 'calendar-events:delete', $calendarEvent);
        $this->assertAuthor($calendarEvent);
        $calendarEvent->delete();

        return response()->json(null, 204);
    }

    /**
     * @return list<array{type: string, id: string}>
     */
    private function assignmentItemsFromStoreRequest(CalendarEventStoreRequest $request): array
    {
        if ($request->has('assignments')) {
            $raw = $request->input('assignments', []);

            return $this->sanitizeAssignmentItems(is_array($raw) ? $raw : []);
        }
        if ($request->filled('task_id')) {
            return [['type' => 'task', 'id' => (string) $request->input('task_id')]];
        }
        if ($request->filled('project_id')) {
            return [['type' => 'project', 'id' => (string) $request->input('project_id')]];
        }

        return [];
    }

    /**
     * @return list<array{type: string, id: string}>
     */
    private function assignmentItemsFromUpdateRequest(CalendarEventUpdateRequest $request): array
    {
        if ($request->has('assignments')) {
            $raw = $request->input('assignments', []);

            return $this->sanitizeAssignmentItems(is_array($raw) ? $raw : []);
        }
        if ($request->filled('task_id')) {
            return [['type' => 'task', 'id' => (string) $request->input('task_id')]];
        }
        if ($request->filled('project_id')) {
            return [['type' => 'project', 'id' => (string) $request->input('project_id')]];
        }

        return [];
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<array{type: string, id: string}>
     */
    private function sanitizeAssignmentItems(array $raw): array
    {
        $items = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = isset($row['type']) ? (string) $row['type'] : '';
            $id = isset($row['id']) ? (string) $row['id'] : '';
            if ($type === '' || $id === '') {
                continue;
            }
            $items[] = ['type' => $type, 'id' => $id];
        }

        return $items;
    }

    /**
     * @param  list<array{type: string, id: string}>  $items
     * @return list<Project|Task>
     */
    private function resolveAssignmentModels(Organization $organization, User $user, array $items): array
    {
        $seen = [];
        $models = [];
        foreach ($items as $item) {
            $type = $item['type'];
            $id = $item['id'];
            $key = $type.':'.$id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($type === 'task') {
                /** @var Task $task */
                $task = Task::query()
                    ->whereBelongsTo($organization, 'organization')
                    ->findOrFail($id);
                $this->assertUserCanAccessProject($organization, $user, $task->project);
                $models[] = $task;
            } elseif ($type === 'project') {
                /** @var Project $project */
                $project = Project::query()
                    ->whereBelongsTo($organization, 'organization')
                    ->findOrFail($id);
                $this->assertUserCanAccessProject($organization, $user, $project);
                $models[] = $project;
            }
        }

        return $models;
    }

    /**
     * @param  list<Project|Task>  $models
     */
    private function syncAssignments(CalendarEvent $event, array $models): void
    {
        $event->assignments()->delete();
        $position = 0;
        foreach ($models as $model) {
            $assignment = new CalendarEventAssignment([
                'calendar_event_id' => $event->getKey(),
                'position' => $position,
            ]);
            $assignment->assignable()->associate($model);
            $assignment->save();
            $position++;
        }
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    private function visibleCalendarEventsQuery(Organization $organization, User $user): Builder
    {
        $canViewAllProjects = $this->hasPermission($organization, 'projects:view:all');
        $canViewAllTasks = $this->hasPermission($organization, 'tasks:view:all');

        return CalendarEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where(function (Builder $q) use ($organization, $user, $canViewAllProjects, $canViewAllTasks): void {
                $q->where(function (Builder $q2) use ($user): void {
                    $q2->where('visibility', NoteVisibility::Private)
                        ->where('user_id', $user->getKey());
                })->orWhere(function (Builder $q2) use ($organization, $user, $canViewAllProjects, $canViewAllTasks): void {
                    $q2->where('visibility', NoteVisibility::Shared)
                        ->where(function (Builder $q3) use ($organization, $user, $canViewAllProjects, $canViewAllTasks): void {
                            $q3->whereDoesntHave('assignments')
                                ->orWhereHas('assignments', function (Builder $qA) use ($organization, $user, $canViewAllProjects): void {
                                    $qA->where('assignable_type', 'project')
                                        ->whereHasMorph('assignable', [Project::class], function (Builder $q4) use ($organization, $user, $canViewAllProjects): void {
                                            $q4->where('organization_id', $organization->getKey());
                                            if (! $canViewAllProjects) {
                                                $q4->visibleByEmployee($user);
                                            }
                                        });
                                })->orWhereHas('assignments', function (Builder $qA) use ($organization, $user, $canViewAllTasks): void {
                                    $qA->where('assignable_type', 'task')
                                        ->whereHasMorph('assignable', [Task::class], function (Builder $q4) use ($organization, $user, $canViewAllTasks): void {
                                            $q4->where('organization_id', $organization->getKey());
                                            if (! $canViewAllTasks) {
                                                $q4->visibleByEmployee($user);
                                            }
                                        });
                                });
                        });
                });
            });
    }

    private function assertUserCanAccessProject(Organization $organization, User $user, Project $project): void
    {
        if ($project->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('Project does not belong to organization');
        }
        if ($this->hasPermission($organization, 'projects:view:all')) {
            return;
        }
        $canSee = Project::query()
            ->where('id', $project->getKey())
            ->whereBelongsTo($organization, 'organization')
            ->visibleByEmployee($user)
            ->exists();
        if (! $canSee) {
            throw new AuthorizationException('You do not have access to this project.');
        }
    }

    private function assertAuthor(CalendarEvent $calendarEvent): void
    {
        if ($calendarEvent->user_id !== $this->user()->getKey()) {
            throw new AuthorizationException;
        }
    }
}
