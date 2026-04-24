<script setup lang="ts">
import FormSection from '@/Components/FormSection.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import type { UpdateOrganizationBody } from '@/packages/api/src';
import BillableRateInput from '@/packages/ui/src/Input/BillableRateInput.vue';
import { useOrganizationStore } from '@/utils/useOrganization';
import { storeToRefs } from 'pinia';
import OrganizationBillableRateModal from '@/Components/Common/Organization/OrganizationBillableRateModal.vue';
import { Checkbox } from '@/packages/ui/src';

const page = usePage<{
    currencies: Record<string, string>;
}>();

const store = useOrganizationStore();
const { fetchOrganization, updateOrganization } = store;
const { organization } = storeToRefs(store);
const saving = ref(false);
const organizationBody = ref<UpdateOrganizationBody>({
    name: '',
    currency: '',
    billable_rate: null as number | null,
    employees_can_see_billable_rates: false,
});

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
    },
    { immediate: true }
);

onMounted(async () => {
    await fetchOrganization();
});

const showConfirmationModal = ref(false);

const billableRateCurrency = () =>
    organizationBody.value.currency || organization.value?.currency || 'EUR';

async function submit() {
    saving.value = true;
    await updateOrganization(organizationBody.value);
    saving.value = false;
    showConfirmationModal.value = false;
    router.reload({ preserveScroll: true });
}

function checkForConfirmationModal() {
    if (organizationBody.value.billable_rate === organization.value?.billable_rate) {
        submit();
    } else {
        showConfirmationModal.value = true;
    }
}
</script>

<template>
    <FormSection>
        <template #title> Billable rate &amp; currency</template>

        <template #description>
            Set the organization currency and default hourly billable rate. Currency applies to money
            amounts across the organization.
        </template>

        <template #form>
            <OrganizationBillableRateModal
                v-model:show="showConfirmationModal"
                :new-billable-rate="organizationBody.billable_rate"
                @submit="submit"></OrganizationBillableRateModal>

            <Field class="col-span-6 sm:col-span-4">
                <FieldLabel for="organizationCurrency">Currency</FieldLabel>
                <select
                    id="organizationCurrency"
                    v-model="organizationBody.currency"
                    name="currency"
                    class="block w-full border-input-border bg-input-background text-text-primary focus:border-input-border-active rounded-md shadow-sm">
                    <option value="" disabled>Select a currency</option>
                    <option
                        v-for="(currencyTranslated, currencyKey) in page.props.currencies"
                        :key="currencyKey"
                        :value="currencyKey">
                        {{ currencyKey }} - {{ currencyTranslated }}
                    </option>
                </select>
            </Field>

            <Field class="col-span-6 sm:col-span-4">
                <FieldLabel for="organizationBillableRate">Organization billable rate</FieldLabel>
                <BillableRateInput
                    v-if="organization"
                    v-model="organizationBody.billable_rate"
                    :currency="billableRateCurrency()"
                    name="organizationBillableRate"></BillableRateInput>
            </Field>

            <div class="col-span-6 sm:col-span-4">
                <Field orientation="horizontal">
                    <Checkbox
                        v-if="organization"
                        id="organizationShowBillableRatesToEmployees"
                        v-model:checked="
                            organizationBody.employees_can_see_billable_rates
                        "></Checkbox>
                    <FieldLabel for="organizationShowBillableRatesToEmployees"
                        >Show billable rates to employees</FieldLabel
                    >
                </Field>
            </div>
        </template>
        <template #actions>
            <PrimaryButton :disabled="saving" @click="checkForConfirmationModal">
                Save
            </PrimaryButton>
        </template>
    </FormSection>
</template>
