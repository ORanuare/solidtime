<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Member;

use App\Enums\Role;
use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Organization;
use App\Rules\CurrencyRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * @property Organization $organization
 */
class MemberUpdateRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|ValidationRule|\Illuminate\Contracts\Validation\Rule>>
     */
    public function rules(): array
    {
        return [
            'role' => [
                'string',
                Rule::enum(Role::class),
            ],
            'billable_rate' => array_merge(
                [
                    'nullable',
                ],
                $this->moneyRules()
            ),
            'billable_rates' => [
                'sometimes',
                'array',
            ],
            'billable_rates.*.currency_code' => [
                'required',
                'string',
                new CurrencyRule,
                Rule::exists('organization_currencies', 'currency_code')->where('organization_id', $this->organization->id),
            ],
            'billable_rates.*.billable_rate' => array_merge(
                [
                    'nullable',
                ],
                $this->moneyRules()
            ),
        ];
    }

    public function getBillableRate(): ?int
    {
        $input = $this->input('billable_rate');

        return $input !== null && $input !== 0 ? (int) $this->input('billable_rate') : null;
    }

    /**
     * @return list<array{currency_code: string, billable_rate?: int|null}>|null
     */
    public function getBillableRates(): ?array
    {
        if (! $this->has('billable_rates')) {
            return null;
        }
        /** @var mixed $raw */
        $raw = $this->input('billable_rates');
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = isset($row['currency_code']) && is_string($row['currency_code']) ? $row['currency_code'] : '';
            $item = ['currency_code' => $code];
            if (array_key_exists('billable_rate', $row)) {
                $br = $row['billable_rate'];
                $item['billable_rate'] = $br !== null && $br !== 0 && $br !== '' ? (int) $br : null;
            }
            $out[] = $item;
        }

        return $out;
    }

    public function getRole(): Role
    {
        return Role::from($this->input('role'));
    }
}
