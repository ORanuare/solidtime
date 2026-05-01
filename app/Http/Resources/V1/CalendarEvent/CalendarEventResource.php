<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\CalendarEvent;

use App\Http\Resources\V1\BaseResource;
use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * @property CalendarEvent $resource
 */
class CalendarEventResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $e = $this->resource->eventable;
        $projectId = '';
        $taskId = '';
        if ($e instanceof Task) {
            $projectId = $e->project_id;
            $taskId = $e->id;
        } elseif ($e instanceof Project) {
            $projectId = $e->id;
        }

        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'starts_at' => $this->formatDateTime($this->resource->starts_at),
            'ends_at' => $this->formatDateTime($this->resource->ends_at),
            'all_day' => $this->resource->all_day,
            'visibility' => $this->resource->visibility->value,
            'user_id' => $this->resource->user_id,
            'user_name' => $this->resource->user?->name ?? '',
            'project_id' => $projectId,
            'task_id' => $taskId,
            'eventable_type' => $this->resource->eventable_type,
            'eventable_id' => $this->resource->eventable_id,
            'eventable_label' => $this->eventableLabel($e),
            'created_at' => $this->formatDateTime($this->resource->created_at),
            'updated_at' => $this->formatDateTime($this->resource->updated_at),
        ];
    }

    private function eventableLabel(?\Illuminate\Database\Eloquent\Model $eventable): string
    {
        if ($eventable instanceof Task) {
            return 'Task: '.$eventable->name;
        }
        if ($eventable instanceof Project) {
            return 'Project: '.$eventable->name;
        }

        return 'Workspace';
    }
}
