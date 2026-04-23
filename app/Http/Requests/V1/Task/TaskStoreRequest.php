<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Task;

use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Korridor\LaravelModelValidationRules\Rules\ExistsEloquent;
use Korridor\LaravelModelValidationRules\Rules\UniqueEloquent;

/**
 * @property Organization $organization Organization from model binding
 */
class TaskStoreRequest extends BaseFormRequest
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
                    $builder = $builder->where('project_id', '=', $this->input('project_id'));
                    $parentId = $this->input('parent_task_id');
                    if ($parentId === null || $parentId === '') {
                        return $builder->whereNull('parent_task_id');
                    }

                    return $builder->where('parent_task_id', '=', $parentId);
                })->withCustomTranslation('validation.task_name_already_exists'),
            ],
            'project_id' => [
                'required',
                ExistsEloquent::make(Project::class, null, function (Builder $builder): Builder {
                    /** @var Builder<Project> $builder */
                    return $builder->whereBelongsTo($this->organization, 'organization');
                })->uuid(),
            ],
            'parent_task_id' => [
                'nullable',
                'uuid',
                Rule::when(
                    fn () => filled($this->input('parent_task_id')),
                    [
                        ExistsEloquent::make(Task::class, null, function (Builder $builder): Builder {
                            /** @var Builder<Task> $builder */
                            return $builder
                                ->whereBelongsTo($this->organization, 'organization')
                                ->where('project_id', '=', $this->input('project_id'))
                                ->whereNull('parent_task_id');
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

    public function getEstimatedTime(): ?int
    {
        $input = $this->input('estimated_time');

        return $input !== null && $input !== 0 ? (int) $this->input('estimated_time') : null;
    }
}
