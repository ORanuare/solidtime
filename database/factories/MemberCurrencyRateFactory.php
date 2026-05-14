<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberCurrencyRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberCurrencyRate>
 */
class MemberCurrencyRateFactory extends Factory
{
    protected $model = MemberCurrencyRate::class;

    /**
     * Prefer {@see self::forMember}; the default nests a Member but needs a workspace currency row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'currency_code' => 'EUR',
            'billable_rate' => null,
        ];
    }

    public function forMember(Member $member, string $currencyCode, ?int $billableRate = null): self
    {
        return $this->state(fn () => [
            'member_id' => $member->getKey(),
            'currency_code' => $currencyCode,
            'billable_rate' => $billableRate,
        ]);
    }
}

