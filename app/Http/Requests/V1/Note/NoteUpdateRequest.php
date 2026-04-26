<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Note;

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
class NoteUpdateRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        $base = [
            'body' => [
                'sometimes',
                'string',
                'min:1',
            ],
            'visibility' => [
                'sometimes',
                'string',
                Rule::enum(NoteVisibility::class),
            ],
            'is_archived' => [
                'sometimes',
                'boolean',
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
            if (! $this->boolean('reassign')) {
                return;
            }
            if ($this->filled('task_id') && $this->filled('project_id')) {
                $v->errors()->add('task_id', 'Provide either a task or a project, not both.');
            }
        });
    }

    public function getIsArchived(): bool
    {
        assert($this->has('is_archived'));

        return (bool) $this->input('is_archived');
    }
}
