<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\CalendarEvent;

use App\Http\Resources\V1\BaseResource;
use App\Models\CalendarEvent;
use App\Models\CalendarEventAssignment;
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
        $assignments = $this->resource->assignments;
        $assignmentPayload = $assignments->map(function (CalendarEventAssignment $a): array {
            $m = $a->assignable;
            if ($m instanceof Task) {
                return [
                    'type' => 'task',
                    'id' => $m->id,
                    'name' => $m->name,
                ];
            }
            if ($m instanceof Project) {
                return [
                    'type' => 'project',
                    'id' => $m->id,
                    'name' => $m->name,
                ];
            }

            return [
                'type' => (string) $a->assignable_type,
                'id' => (string) $a->assignable_id,
                'name' => '',
            ];
        })->values()->all();

        [$projectId, $taskId] = $this->primaryProjectAndTaskIds($assignments);
        $label = $this->assignmentSummaryLabel($assignments);

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
            'assignments' => $assignmentPayload,
            'eventable_label' => $label,
            'created_at' => $this->formatDateTime($this->resource->created_at),
            'updated_at' => $this->formatDateTime($this->resource->updated_at),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CalendarEventAssignment>  $assignments
     * @return array{0: string, 1: string}
     */
    private function primaryProjectAndTaskIds($assignments): array
    {
        $projectId = '';
        $taskId = '';
        foreach ($assignments as $a) {
            $m = $a->assignable;
            if ($m instanceof Task) {
                return [$m->project_id, $m->id];
            }
        }
        foreach ($assignments as $a) {
            $m = $a->assignable;
            if ($m instanceof Project) {
                return [$m->id, ''];
            }
        }

        return [$projectId, $taskId];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CalendarEventAssignment>  $assignments
     */
    private function assignmentSummaryLabel($assignments): string
    {
        if ($assignments->isEmpty()) {
            return 'Workspace';
        }

        $typed = [];
        foreach ($assignments as $a) {
            $m = $a->assignable;
            if ($m instanceof Task) {
                $typed[] = ['task', $m->name];
            } elseif ($m instanceof Project) {
                $typed[] = ['project', $m->name];
            }
        }

        if ($typed === []) {
            return 'Workspace';
        }

        $kinds = array_unique(array_column($typed, 0));
        $names = array_column($typed, 1);

        if (count($names) === 1) {
            return $names[0];
        }

        if (count($kinds) === 1) {
            $scope = $kinds[0] === 'task' ? 'Tasks' : 'Projects';

            return $scope.' · '.implode(', ', $names);
        }

        return implode(', ', $names);
    }
}
