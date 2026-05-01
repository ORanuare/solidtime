<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\CalendarEvent;

use App\Enums\NoteVisibility;
use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Korridor\LaravelModelValidationRules\Rules\ExistsEloquent;

/**
 * @property Organization $organization Organization from model binding
 */
class CalendarEventUpdateRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        $base = [
            'title' => [
                'sometimes',
                'string',
                'max:500',
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:50000',
            ],
            'starts_at' => [
                'sometimes',
                'date',
            ],
            'ends_at' => [
                'sometimes',
                'date',
            ],
            'all_day' => [
                'sometimes',
                'boolean',
            ],
            'visibility' => [
                'sometimes',
                'string',
                Rule::enum(NoteVisibility::class),
            ],
            'reassign' => [
                'sometimes',
                'boolean',
            ],
        ];

        if (! $this->boolean('reassign')) {
            return array_merge($base, [
                'task_id' => [
                    'prohibited',
                ],
                'project_id' => [
                    'prohibited',
                ],
            ]);
        }

        return array_merge($base, [
            'task_id' => [
                'nullable',
                'uuid',
                ExistsEloquent::make(Task::class, null, function (Builder $builder): Builder {
                    /** @var Builder<Task> $builder */
                    return $builder->whereBelongsTo($this->organization, 'organization');
                })->uuid(),
            ],
            'project_id' => [
                'nullable',
                'uuid',
                ExistsEloquent::make(Project::class, null, function (Builder $builder): Builder {
                    /** @var Builder<Project> $builder */
                    return $builder->whereBelongsTo($this->organization, 'organization');
                })->uuid(),
            ],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->boolean('reassign') && $this->filled('task_id') && $this->filled('project_id')) {
                $v->errors()->add('task_id', 'Provide either a task or a project, not both.');
            }

            $hasStart = $this->has('starts_at');
            $hasEnd = $this->has('ends_at');
            if ($hasStart xor $hasEnd) {
                $v->errors()->add('starts_at', __('Start and end must be updated together.'));
            }
            if ($hasStart && $hasEnd) {
                $start = $this->input('starts_at');
                $end = $this->input('ends_at');
                if ($start !== null && $end !== null && strtotime((string) $end) <= strtotime((string) $start)) {
                    $v->errors()->add('ends_at', __('The end must be after the start.'));
                }
            }
        });
    }
}
