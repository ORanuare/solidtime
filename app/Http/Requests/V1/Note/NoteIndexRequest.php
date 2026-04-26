<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Note;

use App\Enums\NoteVisibility;
use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Organization;
use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * @property Organization $organization Organization from model binding
 */
class NoteIndexRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'page' => [
                'integer',
                'min:1',
                'max:2147483647',
            ],
            'project_id' => [
                'nullable',
                'uuid',
            ],
            'task_id' => [
                'nullable',
                'uuid',
            ],
            'visibility' => [
                'nullable',
                'string',
                Rule::enum(NoteVisibility::class),
            ],
            'search' => [
                'nullable',
                'string',
                'max:500',
            ],
            'archived' => [
                'string',
                'in:true,false,all',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('project_id') || ! $this->filled('task_id')) {
                return;
            }

            $task = Task::query()
                ->whereBelongsTo($this->organization, 'organization')
                ->whereKey($this->input('task_id'))
                ->first();

            if ($task === null) {
                $validator->errors()->add('task_id', __('The selected task is invalid.'));

                return;
            }

            if ($task->project_id !== $this->input('project_id')) {
                $validator->errors()->add('task_id', __('The task does not belong to the given project.'));
            }
        });
    }

    public function getFilterArchived(): string
    {
        return $this->input('archived', 'false');
    }
}
