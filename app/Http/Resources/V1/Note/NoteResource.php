<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Note;

use App\Http\Resources\V1\BaseResource;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * @property Note $resource
 */
class NoteResource extends BaseResource
{
    /**
     * @return array<string, string|int|null|array<string, string>>
     */
    public function toArray(Request $request): array
    {
        $n = $this->resource->notable;
        $projectId = '';
        $taskId = '';
        if ($n instanceof Task) {
            $projectId = $n->project_id;
            $taskId = $n->id;
        } elseif ($n instanceof Project) {
            $projectId = $n->id;
        }

        return [
            'id' => $this->resource->id,
            'body' => $this->resource->body,
            'visibility' => $this->resource->visibility->value,
            'is_archived' => $this->resource->is_archived,
            'user_id' => $this->resource->user_id,
            'user_name' => $this->resource->user?->name ?? '',
            'project_id' => $projectId,
            'task_id' => $taskId,
            'notable_type' => $this->resource->notable_type,
            'notable_id' => $this->resource->notable_id,
            'notable_label' => $this->notableLabel($n),
            'created_at' => $this->formatDateTime($this->resource->created_at),
            'updated_at' => $this->formatDateTime($this->resource->updated_at),
        ];
    }

    private function notableLabel(?\Illuminate\Database\Eloquent\Model $notable): string
    {
        if ($notable instanceof Task) {
            return 'Task: '.$notable->name;
        }
        if ($notable instanceof Project) {
            return 'Project: '.$notable->name;
        }

        return 'Workspace';
    }
}
