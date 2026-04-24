<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Note;

use App\Enums\NoteVisibility;
use App\Http\Requests\V1\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

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
                'prohibits:task_id',
            ],
            'task_id' => [
                'nullable',
                'uuid',
                'prohibits:project_id',
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
        ];
    }
}
