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
class CalendarEventStoreRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'title' => [
                'required',
                'string',
                'max:500',
            ],
            'description' => [
                'nullable',
                'string',
                'max:50000',
            ],
            'starts_at' => [
                'required',
                'date',
            ],
            'ends_at' => [
                'required',
                'date',
            ],
            'all_day' => [
                'required',
                'boolean',
            ],
            'visibility' => [
                'required',
                'string',
                Rule::enum(NoteVisibility::class),
            ],
            'assignments' => [
                'sometimes',
                'array',
            ],
            'assignments.*.type' => [
                'required_with:assignments',
                'string',
                Rule::in(['project', 'task']),
            ],
            'assignments.*.id' => [
                'required_with:assignments.*.type',
                'uuid',
            ],
            'task_id' => [
                Rule::prohibitedIf(fn () => $this->has('assignments')),
                'nullable',
                'uuid',
                'prohibits:project_id',
                ExistsEloquent::make(Task::class, null, function (Builder $builder): Builder {
                    /** @var Builder<Task> $builder */
                    return $builder->whereBelongsTo($this->organization, 'organization');
                })->uuid(),
            ],
            'project_id' => [
                Rule::prohibitedIf(fn () => $this->has('assignments')),
                'nullable',
                'uuid',
                'prohibits:task_id',
                ExistsEloquent::make(Project::class, null, function (Builder $builder): Builder {
                    /** @var Builder<Project> $builder */
                    return $builder->whereBelongsTo($this->organization, 'organization');
                })->uuid(),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $start = $this->input('starts_at');
            $end = $this->input('ends_at');
            if ($start !== null && $end !== null && strtotime((string) $end) <= strtotime((string) $start)) {
                $v->errors()->add('ends_at', __('The end must be after the start.'));
            }

            if (! $this->has('assignments')) {
                return;
            }
            $assignments = $this->input('assignments');
            if (! is_array($assignments)) {
                return;
            }
            $seen = [];
            foreach ($assignments as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $type = $row['type'] ?? '';
                $id = $row['id'] ?? '';
                if ($type === '' || $id === '') {
                    continue;
                }
                $key = $type.':'.$id;
                if (isset($seen[$key])) {
                    $v->errors()->add('assignments.'.$i.'.id', __('Duplicate assignment.'));
                }
                $seen[$key] = true;
            }
        });
    }
}
