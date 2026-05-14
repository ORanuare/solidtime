<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Organization;

use App\Enums\CurrencyFormat;
use App\Enums\DateFormat;
use App\Enums\IntervalFormat;
use App\Enums\NumberFormat;
use App\Enums\TimeFormat;
use App\Http\Resources\V1\BaseResource;
use App\Models\Organization;
use App\Models\OrganizationCurrency;
use App\Service\CurrencyService;
use Illuminate\Http\Request;

/**
 * @property Organization $resource
 */
class OrganizationResource extends BaseResource
{
    private bool $showBillableRate;

    /**
     * Create a new resource instance.
     *
     * @return void
     */
    public function __construct(Organization $resource, bool $showBillableRate)
    {
        parent::__construct($resource);

        $this->showBillableRate = $showBillableRate;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, string|bool|int|null>
     */
    public function toArray(Request $request): array
    {
        $currencyService = app(CurrencyService::class);

        return [
            /** @var string $id ID */
            'id' => $this->resource->id,
            /** @var string $name Name */
            'name' => $this->resource->name,
            /** @var string $profile_photo_url Organization profile photo URL */
            'profile_photo_url' => $this->resource->profile_photo_url,
            /** @var bool $color Personal organizations automatically created after registration */
            'is_personal' => $this->resource->personal_team,
            /** @var int|null $billable_rate Billable rate in cents per hour */
            'billable_rate' => $this->showBillableRate ? $this->resource->billable_rate : null,
            /** @var bool $employees_can_see_billable_rates Can members of the organization with role "employee" see the billable rates */
            'employees_can_see_billable_rates' => $this->resource->employees_can_see_billable_rates,
            /** @var bool $employees_can_manage_tasks Can members of the organization with role "employee" manage tasks in public projects and projects they are assigned to */
            'employees_can_manage_tasks' => $this->resource->employees_can_manage_tasks,
            /** @var bool $prevent_overlapping_time_entries Prevent creating overlapping time entries (only new entries) */
            'prevent_overlapping_time_entries' => $this->resource->prevent_overlapping_time_entries,
            /** @var string $currency Currency code (ISO 4217) */
            'currency' => $this->resource->currency,
            /** @var string $currency_symbol Currency symbol */
            'currency_symbol' => $currencyService->getCurrencySymbol($this->resource->currency),
            /** @var array<int, array{currency_code: string, currency_symbol: string, default_billable_rate: int|null, is_primary: bool}> $currencies Enabled workspace currencies */
            'currencies' => $this->resource->relationLoaded('organizationCurrencies')
                ? $this->resource->organizationCurrencies
                    ->sortBy(fn (OrganizationCurrency $c): int => $c->sort_order ?? 0)
                    ->values()
                    ->map(fn (OrganizationCurrency $c): array => [
                        'currency_code' => $c->currency_code,
                        'currency_symbol' => $currencyService->getCurrencySymbol($c->currency_code),
                        'default_billable_rate' => $this->showBillableRate ? $c->default_billable_rate : null,
                        'is_primary' => $c->currency_code === $this->resource->currency,
                    ])->all()
                : [],
            /** @var NumberFormat $number_format Number format */
            'number_format' => $this->resource->number_format->value,
            /** @var CurrencyFormat $currency_format Currency format */
            'currency_format' => $this->resource->currency_format->value,
            /** @var DateFormat $date_format Date format */
            'date_format' => $this->resource->date_format->value,
            /** @var IntervalFormat $interval_format Interval format */
            'interval_format' => $this->resource->interval_format->value,
            /** @var TimeFormat $time_format Time format */
            'time_format' => $this->resource->time_format->value,
        ];
    }
}
