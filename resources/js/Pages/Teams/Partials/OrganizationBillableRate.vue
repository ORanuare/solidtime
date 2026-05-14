<script setup lang="ts">
import FormSection from '@/Components/FormSection.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Field, FieldDescription, FieldLabel } from '@/packages/ui/src/field';
import type { UpdateOrganizationBody } from '@/packages/api/src';
import BillableRateInput from '@/packages/ui/src/Input/BillableRateInput.vue';
import BillableRateModal from '@/packages/ui/src/BillableRateModal.vue';
import { useOrganizationStore } from '@/utils/useOrganization';
import { storeToRefs } from 'pinia';
import { Checkbox } from '@/packages/ui/src';
import { Trash2, Plus } from 'lucide-vue-next';
import { formatCents, getOrganizationCurrencySymbol } from '@/packages/ui/src/utils/money';

const page = usePage<{
    currencies: Record<string, string>;
}>();

type WorkspaceDraftRow = {
    currency_code: string;
    default_billable_rate: number | null;
};

const store = useOrganizationStore();
const { updateOrganization } = store;
const { organization } = storeToRefs(store);
const saving = ref(false);
const organizationBody = ref<UpdateOrganizationBody>({
    name: '',
    currency: '',
    billable_rate: null as number | null,
    employees_can_see_billable_rates: false,
});
const workspaceRows = ref<WorkspaceDraftRow[]>([]);

const propagationBaseline = ref<string>('');
const employeesFingerprintBaseline = ref<string>('');

const isoCandidates = computed(() => Object.keys(page.props.currencies ?? {}).sort());

function normalizePropagationSignature(currency: string, rows: WorkspaceDraftRow[]): string {
    const normalizedRows = [...rows]
        .map((row) => ({
            currency_code: row.currency_code.trim(),
            default_billable_rate: row.default_billable_rate,
        }))
        .filter((row) => row.currency_code.length > 0)
        .sort((a, b) => a.currency_code.localeCompare(b.currency_code));
    return JSON.stringify({
        currency: currency.trim(),
        rows: normalizedRows,
    });
}

const propagationSignature = computed(() =>
    normalizePropagationSignature(organizationBody.value.currency, workspaceRows.value)
);

const employeesFingerprint = computed(() =>
    JSON.stringify({
        employees_can_see_billable_rates: organizationBody.value.employees_can_see_billable_rates,
    })
);

watch(
    organization,
    (org) => {
        if (!org) {
            return;
        }
        organizationBody.value = {
            name: org.name,
            currency: org.currency,
            billable_rate: org.billable_rate,
            employees_can_see_billable_rates: org.employees_can_see_billable_rates ?? false,
        };
        workspaceRows.value =
            org.currencies && org.currencies.length > 0
                ? org.currencies.map((c) => ({
                      currency_code: c.currency_code,
                      default_billable_rate:
                          c.default_billable_rate !== undefined && c.default_billable_rate !== null
                              ? c.default_billable_rate
                              : null,
                  }))
                : [
                      {
                          currency_code: org.currency,
                          default_billable_rate:
                              org.billable_rate !== undefined && org.billable_rate !== null
                                  ? org.billable_rate
                                  : null,
                      },
                  ];
        syncLegacyBillableFromPrimaryRow();
        propagationBaseline.value = propagationSignature.value;
        employeesFingerprintBaseline.value = JSON.stringify({
            employees_can_see_billable_rates: org.employees_can_see_billable_rates ?? false,
        });
    },
    { immediate: true }
);

watch(
    [workspaceRows, () => organizationBody.value.currency],
    () => {
        syncLegacyBillableFromPrimaryRow();
    },
    { deep: true }
);

function syncLegacyBillableFromPrimaryRow(): void {
    const row = workspaceRows.value.find(
        (r) => r.currency_code === organizationBody.value.currency && r.currency_code.trim().length > 0
    );
    if (row) {
        organizationBody.value.billable_rate = row.default_billable_rate;
    }
}

const populatedRows = computed(() =>
    workspaceRows.value.filter((r) => r.currency_code.trim().length > 0)
);

function isoOptionsForRowIndex(index: number): string[] {
    const current = workspaceRows.value[index]?.currency_code;
    const otherSelected = new Set(
        workspaceRows.value
            .map((r, i) => (i !== index ? r.currency_code.trim() : ''))
            .filter(Boolean)
    );
    return isoCandidates.value.filter((code) => !otherSelected.has(code) || code === current);
}

const canAddCurrency = computed(() => {
    const used = new Set(populatedRows.value.map((r) => r.currency_code));
    return isoCandidates.value.some((c) => !used.has(c));
});

function firstAvailableCurrency(): string | null {
    const used = new Set(populatedRows.value.map((r) => r.currency_code));
    for (const c of isoCandidates.value) {
        if (!used.has(c)) {
            return c;
        }
    }
    return null;
}

function addCurrencyRow(): void {
    const nextCode = firstAvailableCurrency();
    if (!nextCode) {
        return;
    }
    workspaceRows.value.push({ currency_code: nextCode, default_billable_rate: null });
    if (!organizationBody.value.currency.trim()) {
        organizationBody.value.currency = nextCode;
    }
}

function removeCurrencyRow(index: number): void {
    if (workspaceRows.value.length <= 1) {
        return;
    }
    const oldPrimary = organizationBody.value.currency;
    const removed = workspaceRows.value[index];
    workspaceRows.value.splice(index, 1);
    if (removed?.currency_code === oldPrimary) {
        const next = workspaceRows.value.find((r) => r.currency_code.trim().length > 0);
        organizationBody.value.currency = next?.currency_code.trim() ?? '';
        syncLegacyBillableFromPrimaryRow();
    }
}

const primarySelectOptions = computed(() => populatedRows.value.map((r) => r.currency_code));

const propagationChanged = computed(
    () => propagationBaseline.value !== '' && propagationSignature.value !== propagationBaseline.value
);

const employeesOnlyChanges = computed(
    () =>
        !propagationChanged.value &&
        employeesFingerprint.value !== employeesFingerprintBaseline.value &&
        employeesFingerprintBaseline.value !== ''
);

const confirmModalSaving = ref(false);

const showConfirmationModal = ref(false);

function propagationSummaryLines(): Array<{ currency: string; label: string }> {
    return populatedRows.value.map((row) => ({
        currency: row.currency_code,
        label:
            row.default_billable_rate !== null && row.default_billable_rate !== undefined
                ? formatCents(
                      row.default_billable_rate,
                      row.currency_code,
                      organization.value?.currency_format,
                      getOrganizationCurrencySymbol(row.currency_code),
                      organization.value?.number_format
                  )
                : 'None',
    }));
}

function checkForConfirmationModal(): void {
    if (!organization.value) {
        return;
    }
    const nothingToSave =
        propagationBaseline.value !== '' &&
        propagationSignature.value === propagationBaseline.value &&
        employeesFingerprint.value === employeesFingerprintBaseline.value;
    if (nothingToSave) {
        return;
    }
    if (employeesOnlyChanges.value) {
        void persistEmployeesPreferenceOnly();
        return;
    }
    syncLegacyBillableFromPrimaryRow();
    if (!propagationChanged.value) {
        void submitWithoutModalConfirm();
        return;
    }
    showConfirmationModal.value = true;
}

async function persistEmployeesPreferenceOnly(): Promise<void> {
    if (!organization.value) {
        return;
    }
    saving.value = true;
    try {
        await updateOrganization({
            employees_can_see_billable_rates: organizationBody.value.employees_can_see_billable_rates,
        });
        await router.reload({
            preserveScroll: true,
        });
    } finally {
        saving.value = false;
    }
}

async function persistWorkspaceBilling(opts: {
    closeModalAfter: boolean;
    includeCurrenciesPayload: boolean;
}): Promise<void> {
    if (!organization.value) {
        return;
    }
    saving.value = true;
    try {
        syncLegacyBillableFromPrimaryRow();
        const rowsToSend = workspaceRows.value.filter((r) => r.currency_code.trim().length > 0);
        if (
            opts.includeCurrenciesPayload &&
            (rowsToSend.length === 0 ||
                rowsToSend.some(
                    (a, i) => rowsToSend.findIndex((b) => b.currency_code === a.currency_code) !== i
                ) ||
                !rowsToSend.some((r) => r.currency_code === organizationBody.value.currency))
        ) {
            if (opts.closeModalAfter) {
                showConfirmationModal.value = false;
            }
            return;
        }
        const payload: UpdateOrganizationBody = {
            name: organizationBody.value.name,
            currency: organizationBody.value.currency,
            billable_rate: organizationBody.value.billable_rate,
            employees_can_see_billable_rates: organizationBody.value.employees_can_see_billable_rates,
        };
        if (opts.includeCurrenciesPayload) {
            payload.currencies = rowsToSend.map((r) => ({
                currency_code: r.currency_code,
                default_billable_rate: r.default_billable_rate,
            }));
        }
        await updateOrganization(payload);
        if (opts.closeModalAfter) {
            showConfirmationModal.value = false;
        }
        await router.reload({
            preserveScroll: true,
        });
    } finally {
        saving.value = false;
    }
}

async function submitWithoutModalConfirm(): Promise<void> {
    await persistWorkspaceBilling({ closeModalAfter: false, includeCurrenciesPayload: false });
}

async function submitFromConfirmationModal(): Promise<void> {
    confirmModalSaving.value = true;
    try {
        await persistWorkspaceBilling({ closeModalAfter: true, includeCurrenciesPayload: true });
    } finally {
        confirmModalSaving.value = false;
    }
}
</script>

<template>
    <FormSection>
        <template #title>Billable rates &amp; currencies</template>

        <template #description>
            Enable workspace ISO currencies with a default hourly rate each. Primary currency drives
            organization-wide summaries and formatting. Rates are not converted between currencies (no FX).
        </template>

        <template #form>
            <BillableRateModal
                v-model:show="showConfirmationModal"
                v-model:saving="confirmModalSaving"
                title="Update workspace default billable rates?"
                @submit="submitFromConfirmationModal">
                <p class="py-2 text-center text-sm text-text-secondary leading-relaxed">
                    Changing default hourly rates may update existing billable time entries where these
                    workspace defaults apply for the matching currency.
                </p>
                <ul
                    class="mx-auto mt-3 max-w-sm space-y-1.5 text-sm text-text-primary rounded-md bg-card-background border-card-border px-4 py-3 border tabular-nums">
                    <li
                        v-for="line in propagationSummaryLines()"
                        :key="line.currency"
                        class="flex justify-between gap-4">
                        <span>{{ line.currency }}</span>
                        <span>{{ line.label }}</span>
                    </li>
                </ul>
                <p class="pt-3 text-center text-sm text-text-secondary">
                    Refresh matching time entries now?
                </p>
            </BillableRateModal>

            <Field class="col-span-full">
                <div class="space-y-3">
                    <div class="rounded-lg border border-card-border divide-y divide-card-border">
                        <div
                            v-for="(row, index) in workspaceRows"
                            :key="`${index}-${row.currency_code}`"
                            class="flex flex-wrap items-start gap-3 p-4 sm:flex-nowrap">
                            <div class="grow min-w-[160px] sm:col-span-1">
                                <FieldLabel class="pb-2">Currency</FieldLabel>
                                <select
                                    v-model="row.currency_code"
                                    class="block w-full border-input-border bg-input-background text-text-primary focus:border-input-border-active rounded-md shadow-sm text-sm">
                                    <option value="" disabled>Select a currency</option>
                                    <option
                                        v-for="code in isoOptionsForRowIndex(index)"
                                        :key="code"
                                        :value="code">
                                        {{
                                            page.props.currencies?.[code]
                                                ? `${code} — ${page.props.currencies[code]}`
                                                : code
                                        }}
                                    </option>
                                </select>
                            </div>
                            <div class="grow min-w-[220px] flex-1">
                                <FieldLabel class="pb-2">Default hourly billable rate</FieldLabel>
                                <BillableRateInput
                                    v-if="row.currency_code"
                                    v-model="row.default_billable_rate"
                                    :currency="row.currency_code || 'EUR'"
                                    :name="`orgWorkspaceRate-${index}`" />
                                <p v-else class="text-sm text-text-secondary pt-2">Pick a currency first.</p>
                            </div>
                            <div class="flex items-end gap-2 shrink-0 sm:pb-2">
                                <SecondaryButton
                                    type="button"
                                    size="small"
                                    class="h-9 w-9 p-0 shrink-0"
                                    :disabled="workspaceRows.length <= 1 || !organization"
                                    :title="
                                        workspaceRows.length <= 1
                                            ? 'At least one currency is required'
                                            : 'Remove currency'
                                    "
                                    @click="removeCurrencyRow(index)">
                                    <Trash2 class="h-4 w-4 text-icon-default" />
                                </SecondaryButton>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 items-center">
                        <SecondaryButton type="button" size="small" :disabled="!canAddCurrency || saving" @click="addCurrencyRow">
                            <Plus class="h-4 w-4 shrink-0 me-1 inline" /> Add workspace currency </SecondaryButton>
                        <span v-if="!canAddCurrency" class="text-xs text-text-secondary">
                            All currencies from this list are already added for this workspace.
                        </span>
                    </div>
                    <Field class="sm:col-span-4 max-w-md">
                        <FieldLabel for="organizationPrimaryCurrency">Primary workspace currency</FieldLabel>
                        <select
                            id="organizationPrimaryCurrency"
                            v-model="organizationBody.currency"
                            class="block w-full border-input-border bg-input-background text-text-primary focus:border-input-border-active rounded-md shadow-sm">
                            <template v-if="primarySelectOptions.length">
                                <option v-for="code in primarySelectOptions" :key="'primary-' + code" :value="code">
                                    {{
                                        page.props.currencies?.[code]
                                            ? `${code} — ${page.props.currencies[code]}`
                                            : code
                                    }}
                                </option>
                            </template>
                            <option v-else value="" disabled>Add at least one currency row</option>
                        </select>
                        <FieldDescription class="mt-2">
                            The server rejects removing a workspace currency while a project uses it (you will see a validation error).
                        </FieldDescription>
                    </Field>
                </div>
            </Field>

            <div class="col-span-6 sm:col-span-4">
                <Field orientation="horizontal">
                    <Checkbox
                        v-if="organization"
                        id="organizationShowBillableRatesToEmployees"
                        v-model:checked="organizationBody.employees_can_see_billable_rates" />
                    <FieldLabel for="organizationShowBillableRatesToEmployees"
                        >Show billable rates to employees</FieldLabel
                    >
                </Field>
            </div>
        </template>
        <template #actions>
            <PrimaryButton :disabled="saving" @click="checkForConfirmationModal">Save</PrimaryButton>
        </template>
    </FormSection>
</template>

<style scoped></style>
