<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationCurrency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Primary currencies are seeded on {@see Organization} creation. Default factory builds an extra
 * workspace currency distinct from that primary.
 *
 * @extends Factory<OrganizationCurrency>
 */
class OrganizationCurrencyFactory extends Factory
{
    protected $model = OrganizationCurrency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organization = Organization::factory()->create();
        $alternate = collect(['EUR', 'USD', 'GBP'])
            ->first(fn (string $code): bool => $code !== $organization->currency)
            ?? 'USD';

        return [
            'organization_id' => $organization->getKey(),
            'currency_code' => $alternate,
            'default_billable_rate' => null,
            'sort_order' => 1,
        ];
    }

    public function forOrganizationAndCurrency(
        Organization $organization,
        string $currencyCode,
        ?int $defaultBillableRate = null,
        ?int $sortOrder = null,
    ): self {
        return $this->state(fn (): array => [
            'organization_id' => $organization->getKey(),
            'currency_code' => $currencyCode,
            'default_billable_rate' => $defaultBillableRate,
            'sort_order' => $sortOrder ?? 1,
        ]);
    }
}
