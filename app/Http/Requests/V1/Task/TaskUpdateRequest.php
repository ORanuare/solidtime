<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Task;

use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Organization;
use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Korridor\LaravelModelValidationRules\Rules\ExistsEloquent;
use Korridor\LaravelModelValidationRules\Rules\UniqueEloquent;

/**
 * @property Organization $organization Organization from model binding
 * @property Task|null $task Task from model binding
 */
class TaskUpdateRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:255',
                UniqueEloquent::make(Task::class, 'name', function (Builder $builder): Builder {
                    /** @var Builder<Task> $builder */
                    $builder = $builder->where('project_id', '=', $this->task->project_id);
                    $parentId = $this->has('parent_task_id')
                        ? $this->input('parent_task_id')
                        : $this->task->parent_task_id;
                    if ($parentId === null || $parentId === '') {
                        return $builder->whereNull('parent_task_id');
                    }

                    return $builder->where('parent_task_id', '=', $parentId);
                })->ignore($this->task?->getKey())->withCustomTranslation('validation.task_name_already_exists'),
            ],
            'is_done' => [
                'boolean',
            ],
            'parent_task_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::when(
                    fn () => filled($this->input('parent_task_id')),
                    [
                        Rule::notIn(array_merge(
                            [$this->task->getKey()],
                            Task::descendantIdsFor($this->task->getKey())
                        )),
                        ExistsEloquent::make(Task::class, null, function (Builder $builder): Builder {
                            /** @var Builder<Task> $builder */
                            return $builder
                                ->whereBelongsTo($this->organization, 'organization')
                                ->where('project_id', '=', $this->task->project_id);
                        }),
                    ]
                ),
            ],
            // Estimated time in seconds
            'estimated_time' => [
                'nullable',
                'integer',
                'min:0',
                'max:2147483647',
            ],
        ];
    }

    public function getIsDone(): bool
    {
        assert($this->has('is_done'));

        return $this->boolean('is_done');
    }

    public function getEstimatedTime(): ?int
    {
        $input = $this->input('estimated_time');

        return $input !== null && $input !== 0 ? (int) $this->input('estimated_time') : null;
    }
}
