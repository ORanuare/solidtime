<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Note;

use App\Enums\NoteVisibility;
use App\Http\Requests\V1\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class NoteUpdateRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
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
        ];
    }

    public function getIsArchived(): bool
    {
        assert($this->has('is_archived'));

        return (bool) $this->input('is_archived');
    }
}
