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
            $query->where('eventable_type', 'task')
                ->where('eventable_id', $taskId);
        } elseif ($request->filled('project_id')) {
            $projectId = $request->input('project_id');
            $query->where(function (Builder $q) use ($projectId): void {
                $q->where(function (Builder $q2) use ($projectId): void {
                    $q2->where(function (Builder $q3) use ($projectId): void {
                        $q3->where('eventable_type', 'project')
                            ->where('eventable_id', $projectId);
                    })->orWhere(function (Builder $q3) use ($projectId): void {
                        $q3->where('eventable_type', 'task')
                            ->whereHasMorph('eventable', [Task::class], function (Builder $q4) use ($projectId): void {
                                $q4->where('project_id', $projectId);
                            });
                    });
                })->orWhere(function (Builder $q2): void {
                    $this->scopeWorkspaceEventables($q2);
                });
            });
        }

        if ($request->filled('visibility')) {
            $query->where('visibility', $request->input('visibility'));
        }

        $events = $query
            ->with(['user', 'eventable'])
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

        $event = new CalendarEvent;
        $event->title = $request->input('title');
        $event->description = $request->input('description');
        $event->starts_at = Carbon::parse($request->input('starts_at'));
        $event->ends_at = Carbon::parse($request->input('ends_at'));
        $event->all_day = $request->boolean('all_day');
        $event->visibility = NoteVisibility::from($request->input('visibility'));
        $event->user()->associate($user);
        $event->organization()->associate($organization);
        if ($task !== null) {
            $event->eventable()->associate($task);
        } elseif ($project !== null) {
            $event->eventable()->associate($project);
        } else {
            $event->eventable_type = null;
            $event->eventable_id = null;
        }
        $event->save();
        $event->load(['user', 'eventable']);

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
            if ($request->filled('task_id')) {
                $task = Task::query()
                    ->whereBelongsTo($organization, 'organization')
                    ->findOrFail($request->input('task_id'));
                $this->assertUserCanAccessProject($organization, $user, $task->project);
                $calendarEvent->eventable()->associate($task);
            } elseif ($request->filled('project_id')) {
                $project = Project::query()
                    ->whereBelongsTo($organization, 'organization')
                    ->findOrFail($request->input('project_id'));
                $this->assertUserCanAccessProject($organization, $user, $project);
                $calendarEvent->eventable()->associate($project);
            } else {
                $calendarEvent->eventable_type = null;
                $calendarEvent->eventable_id = null;
            }
        }
        $calendarEvent->save();
        $calendarEvent->load(['user', 'eventable']);

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
     * @param  Builder<CalendarEvent>  $query
     */
    private function scopeWorkspaceEventables(Builder $query): void
    {
        $query->whereNull('eventable_type')
            ->whereNull('eventable_id');
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
                        ->where(function (Builder $q3): void {
                            $q3->whereNull('eventable_type')
                                ->whereNull('eventable_id');
                        })->orWhere(function (Builder $q3) use ($organization, $user, $canViewAllProjects): void {
                            $q3->where('eventable_type', 'project')
                                ->whereHasMorph('eventable', [Project::class], function (Builder $q4) use ($organization, $user, $canViewAllProjects): void {
                                    $q4->where('organization_id', $organization->getKey());
                                    if (! $canViewAllProjects) {
                                        $q4->visibleByEmployee($user);
                                    }
                                });
                        })->orWhere(function (Builder $q3) use ($organization, $user, $canViewAllTasks): void {
                            $q3->where('eventable_type', 'task')
                                ->whereHasMorph('eventable', [Task::class], function (Builder $q4) use ($organization, $user, $canViewAllTasks): void {
                                    $q4->where('organization_id', $organization->getKey());
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
