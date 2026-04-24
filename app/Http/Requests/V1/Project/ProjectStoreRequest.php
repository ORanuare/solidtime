<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Project;

use App\Enums\ProjectBillingType;
use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Rules\ColorRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Korridor\LaravelModelValidationRules\Rules\ExistsEloquent;
use Korridor\LaravelModelValidationRules\Rules\UniqueEloquent;

/**
 * @property Organization $organization Organization from model binding
 */
class ProjectStoreRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            // Name of the project, the name needs to be unique per client and organization
            'name' => [
                'required',
                'string',
                'min:1',
                'max:255',
                UniqueEloquent::make(Project::class, 'name', function (Builder $builder): Builder {
                    /** @var Builder<Project> $builder */
                    $clientId = $this->input('client_id');
                    if (! is_string($clientId) || ! Str::isUuid($clientId)) {
                        $clientId = null;
                    }

                    return $builder->whereBelongsTo($this->organization, 'organization')
                        ->where('client_id', $clientId);
                })->withCustomTranslation('validation.project_name_already_exists'),
            ],
            'color' => [
                'required',
                'string',
                'max:255',
                new ColorRule,
            ],
            'is_billable' => [
                'required',
                'boolean',
            ],
            'billing_type' => [
                'nullable',
                Rule::enum(ProjectBillingType::class),
            ],
            'fixed_price' => array_merge(
                [
                    'nullable',
                ],
                $this->moneyRules(true)
            ),
            'billable_rate' => array_merge(
                [
                    'nullable',
                ],
                $this->moneyRules()
            ),
            // ID of the client
            'client_id' => [
                'present',
                'nullable',
                ExistsEloquent::make(Client::class, null, function (Builder $builder): Builder {
                    /** @var Builder<Client> $builder */
                    return $builder->whereBelongsTo($this->organization, 'organization');
                })->uuid(),
            ],
            // Estimated time in seconds
            'estimated_time' => [
                'nullable',
                'integer',
                'min:0',
                'max:2147483647',
            ],
            // Whether the project is public
            'is_public' => [
                'boolean',
            ],
            'is_paid' => [
                'boolean',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $billingType = $this->getBillingType();
            if ($billingType === ProjectBillingType::Fixed) {
                if (! $this->boolean('is_billable')) {
                    $validator->errors()->add('is_billable', __('validation.required'));
                }
                $fixed = $this->input('fixed_price');
                if ($fixed === null || (int) $fixed <= 0) {
                    $validator->errors()->add('fixed_price', __('validation.min.numeric', ['attribute' => 'fixed price', 'min' => 1]));
                }
            } else {
                if ($this->input('fixed_price') !== null && $this->input('fixed_price') !== '') {
                    $validator->errors()->add('fixed_price', __('validation.prohibited'));
                }
            }
        });
    }

    public function getBillingType(): ProjectBillingType
    {
        $raw = $this->input('billing_type');

        return ProjectBillingType::tryFrom(is_string($raw) ? $raw : '') ?? ProjectBillingType::Hourly;
    }

    public function getFixedPrice(): ?int
    {
        if ($this->getBillingType() === ProjectBillingType::Hourly) {
            return null;
        }
        $input = $this->input('fixed_price');

        return $input !== null && $input !== '' ? (int) $input : null;
    }

    public function getIsPublic(): bool
    {
        return $this->has('is_public') && $this->boolean('is_public');
    }

    public function getIsPaid(): bool
    {
        return $this->has('is_paid') ? $this->boolean('is_paid') : true;
    }

    public function getBillableRate(): ?int
    {
        if ($this->getBillingType() === ProjectBillingType::Fixed) {
            return null;
        }
        $input = $this->input('billable_rate');

        return $input !== null && $input !== 0 ? (int) $this->input('billable_rate') : null;
    }

    public function getEstimatedTime(): ?int
    {
        $input = $this->input('estimated_time');

        return $input !== null && $input !== 0 ? (int) $this->input('estimated_time') : null;
    }
}
