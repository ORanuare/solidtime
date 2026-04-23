<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Chart;

use App\Http\Requests\V1\BaseFormRequest;

class WeekOffsetQueryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'week_offset' => ['nullable', 'integer', 'min:-1000', 'max:0'],
        ];
    }

    public function weekOffset(): int
    {
        if (! $this->filled('week_offset')) {
            return 0;
        }

        return (int) $this->input('week_offset');
    }
}
