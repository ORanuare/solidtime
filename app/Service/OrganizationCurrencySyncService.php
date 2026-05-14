<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Organization;
use App\Models\OrganizationCurrency;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationCurrencySyncService
{
    /**
     * Replace workspace currency configuration. Primary remains {@see Organization::$currency};
     * that code must remain in the list (or appear in the payload).
     *
     * @param  list<array{currency_code: string, default_billable_rate?: int|null}>  $currencies
     * @return list<string> ISO codes that were written (for billable-rate propagation)
     */
    public function syncCurrencies(Organization $organization, array $currencies): array
    {
        if ($currencies === []) {
            throw ValidationException::withMessages([
                'currencies' => [__('validation.required', ['attribute' => 'currencies'])],
            ]);
        }

        $codes = collect($currencies)->pluck('currency_code')->map(fn (string $c): string => $c)->all();
        if (count($codes) !== count(array_unique($codes))) {
            throw ValidationException::withMessages([
                'currencies' => [__('validation.distinct', ['attribute' => 'currency_code'])],
            ]);
        }

        if (! in_array($organization->currency, $codes, true)) {
            throw ValidationException::withMessages([
                'currencies' => [__('validation.in', ['attribute' => 'currencies'])],
            ]);
        }

        $currentProjectCurrencies = Project::query()
            ->whereBelongsTo($organization, 'organization')
            ->whereNotNull('currency')
            ->distinct()
            ->pluck('currency')
            ->all();

        foreach ($currentProjectCurrencies as $projectCurrency) {
            if (! in_array($projectCurrency, $codes, true)) {
                throw ValidationException::withMessages([
                    'currencies' => [__('validation.workspace_currency_still_in_use', ['currency' => $projectCurrency])],
                ]);
            }
        }

        $touched = [];
        DB::transaction(function () use ($organization, $currencies, &$touched): void {
            OrganizationCurrency::query()
                ->where('organization_id', '=', $organization->getKey())
                ->whereNotIn('currency_code', collect($currencies)->pluck('currency_code')->all())
                ->delete();

            foreach ($currencies as $index => $row) {
                $code = $row['currency_code'];
                $rate = array_key_exists('default_billable_rate', $row) ? $row['default_billable_rate'] : null;
                OrganizationCurrency::query()->updateOrCreate(
                    [
                        'organization_id' => $organization->getKey(),
                        'currency_code' => $code,
                    ],
                    [
                        'default_billable_rate' => $rate,
                        'sort_order' => $index,
                    ]
                );
                $touched[] = $code;
            }
        });

        return $touched;
    }

    /**
     * Upsert the primary currency row from organization fields (legacy + new model).
     */
    public function syncPrimaryRowFromOrganization(Organization $organization): void
    {
        OrganizationCurrency::query()->updateOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'currency_code' => $organization->currency,
            ],
            [
                'default_billable_rate' => $organization->billable_rate,
                'sort_order' => 0,
            ]
        );
    }
}
