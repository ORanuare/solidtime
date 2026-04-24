<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\ProjectMember;

use App\Enums\ProjectBillingType;
use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Project;
use App\Models\Member;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Korridor\LaravelModelValidationRules\Rules\ExistsEloquent;

/**
 * @property Organization $organization Organization from model binding
 */
class ProjectMemberStoreRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'member_id' => [
                'required',
                ExistsEloquent::make(Member::class, null, function (Builder $builder): Builder {
                    /** @var Builder<Member> $builder */
                    return $builder->whereBelongsTo($this->organization, 'organization');
                })->uuid(),
            ],
            'billable_rate' => array_merge(
                [
                    'nullable',
                    Rule::prohibitedIf(function (): bool {
                        $project = $this->route('project');
                        return $project instanceof Project && $project->billing_type === ProjectBillingType::Fixed;
                    }),
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
}
