<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Requests\V1\Organization\OrganizationUpdateRequest;
use App\Http\Resources\V1\Organization\OrganizationResource;
use App\Models\Organization;
use App\Service\BillableRateService;
use App\Service\OrganizationCurrencySyncService;
use Illuminate\Auth\Access\AuthorizationException;

class OrganizationController extends Controller
{
    /**
     * Get organization
     *
     * @operationId getOrganization
     *
     * @throws AuthorizationException
     */
    public function show(Organization $organization): OrganizationResource
    {
        $this->checkPermission($organization, 'organizations:view');

        $showBillableRate = $this->member($organization)->role !== Role::Employee->value || $organization->employees_can_see_billable_rates;

        $organization->load(['organizationCurrencies']);

        return new OrganizationResource($organization, $showBillableRate);
    }

    /**
     * Update organization
     *
     * @operationId updateOrganization
     *
     * @throws AuthorizationException
     */
    public function update(Organization $organization, OrganizationUpdateRequest $request, BillableRateService $billableRateService, OrganizationCurrencySyncService $organizationCurrencySyncService): OrganizationResource
    {
        $this->checkPermission($organization, 'organizations:update');

        $previousPrimaryCurrency = $organization->currency;
        $touchedWorkspaceCurrencies = [];

        if ($request->getCurrency() !== null) {
            $organization->currency = $request->getCurrency();
        }

        if ($request->getCurrencies() !== null) {
            $touchedWorkspaceCurrencies = $organizationCurrencySyncService->syncCurrencies($organization, $request->getCurrencies());
        }

        if ($request->getName() !== null) {
            $organization->name = $request->getName();
        }
        if ($request->getEmployeesCanSeeBillableRates() !== null) {
            $organization->employees_can_see_billable_rates = $request->getEmployeesCanSeeBillableRates();
        }
        if ($request->getEmployeesCanManageTasks() !== null) {
            $organization->employees_can_manage_tasks = $request->getEmployeesCanManageTasks();
        }
        if ($request->getNumberFormat() !== null) {
            $organization->number_format = $request->getNumberFormat();
        }
        if ($request->getCurrencyFormat() !== null) {
            $organization->currency_format = $request->getCurrencyFormat();
        }
        if ($request->getDateFormat() !== null) {
            $organization->date_format = $request->getDateFormat();
        }
        if ($request->getIntervalFormat() !== null) {
            $organization->interval_format = $request->getIntervalFormat();
        }
        if ($request->getTimeFormat() !== null) {
            $organization->time_format = $request->getTimeFormat();
        }
        if ($request->getPreventOverlappingTimeEntries() !== null) {
            $organization->prevent_overlapping_time_entries = $request->getPreventOverlappingTimeEntries();
        }
        $hasBillableRate = $request->has('billable_rate');
        $oldBillableRate = $organization->billable_rate;
        if ($hasBillableRate) {
            $organization->billable_rate = $request->getBillableRate();
        }
        $organization->save();

        $organizationCurrencySyncService->syncPrimaryRowFromOrganization($organization);

        $primaryChanged = $request->getCurrency() !== null && $organization->currency !== $previousPrimaryCurrency;
        $billableRateAtPrimaryChanged = $hasBillableRate && $oldBillableRate !== $organization->billable_rate;

        if ($primaryChanged) {
            $billableRateService->updateTimeEntriesBillableRateForOrganization($organization, null);
        } elseif ($request->getCurrencies() !== null || $billableRateAtPrimaryChanged) {
            $codes = $touchedWorkspaceCurrencies;
            if ($billableRateAtPrimaryChanged) {
                $codes[] = $organization->currency;
            }
            $codes = array_values(array_unique($codes));
            $billableRateService->updateTimeEntriesBillableRateForOrganization($organization, $codes);
        }

        $organization->load(['organizationCurrencies']);

        return new OrganizationResource($organization, true);
    }
}
