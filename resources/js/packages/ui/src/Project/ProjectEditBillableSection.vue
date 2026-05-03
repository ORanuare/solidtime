<script setup lang="ts">
import { Field, FieldDescription, FieldLabel } from '../field';
import BillableRateInput from '@/packages/ui/src/Input/BillableRateInput.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '..';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/packages/ui/src/tooltip';
import { computed, onMounted, ref, watch } from 'vue';
import BillableIcon from '@/packages/ui/src/Icons/BillableIcon.vue';

const props = defineProps<{
    currency: string;
    organizationBillableRate: number | null;
}>();

type RateType = 'default-rate' | 'custom-rate';

const billableDefault = ref<'billable' | 'non-billable'>('non-billable');
const rateType = ref<RateType>('default-rate');

const billableRate = defineModel<number | null>('billableRate');
const isBillable = defineModel<boolean>('isBillable');
const billingType = defineModel<'hourly' | 'fixed'>('billingType', { default: 'hourly' });
const fixedPrice = defineModel<number | null>('fixedPrice');
const amountReceived = defineModel<number | null>('amountReceived');

onMounted(() => {
    if (isBillable.value === true) {
        billableDefault.value = 'billable';
        rateType.value = billableRate.value ? 'custom-rate' : 'default-rate';
    }
});

watch(billableDefault, () => {
    if (billableDefault.value === 'non-billable') {
        isBillable.value = false;
    } else {
        isBillable.value = true;
    }
});

watch(rateType, () => {
    if (rateType.value === 'default-rate') {
        billableRate.value = null;
    } else if (rateType.value === 'custom-rate') {
        billableDefault.value = 'billable';
        isBillable.value = true;
        if (!billableRate.value) {
            billableRate.value = props.organizationBillableRate ?? null;
        }
    }
});

watch(billingType, () => {
    if (billingType.value === 'fixed') {
        billableDefault.value = 'billable';
        isBillable.value = true;
        rateType.value = 'default-rate';
        billableRate.value = null;
    } else {
        fixedPrice.value = null;
        amountReceived.value = null;
    }
});

const paymentReceivedPercentHint = computed(() => {
    const fp = fixedPrice.value;
    const ar = amountReceived.value;
    if (fp == null || fp <= 0 || ar == null) {
        return null;
    }
    const pct = Math.min(100, Math.max(0, Math.round((ar / fp) * 100)));

    return `${pct}% of contract received`;
});

const displayedRate = computed({
    get() {
        if (rateType.value === 'default-rate') {
            return props.organizationBillableRate ?? null;
        }
        return billableRate.value;
    },
    set(value: number | null) {
        if (rateType.value === 'custom-rate') {
            billableRate.value = value;
        }
    },
});

const billableDescription = computed(() => {
    if (billableDefault.value === 'non-billable') {
        return 'New time entries for this project will not be marked billable by default.';
    }
    return 'New time entries for this project will be marked billable by default.';
});

const emit = defineEmits(['submit']);
</script>

<template>
    <Field>
        <FieldLabel for="billingType" :icon="BillableIcon">Billing</FieldLabel>
        <Select v-model="billingType">
            <SelectTrigger id="billingType">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="hourly">Hourly rate</SelectItem>
                <SelectItem value="fixed">Fixed price</SelectItem>
            </SelectContent>
        </Select>
        <FieldDescription>
            Fixed-price revenue is split across reporting rows by billable time in each view.
        </FieldDescription>
    </Field>
    <template v-if="billingType === 'hourly'">
        <Field>
            <FieldLabel for="billable" :icon="BillableIcon">Billable Default</FieldLabel>
            <Select v-model="billableDefault">
                <SelectTrigger id="billable">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="non-billable">Non-billable</SelectItem>
                    <SelectItem value="billable">Billable</SelectItem>
                </SelectContent>
            </Select>
            <FieldDescription>{{ billableDescription }}</FieldDescription>
        </Field>
        <Field>
            <FieldLabel :icon="BillableIcon" for="billableRateType">Billable Rate</FieldLabel>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <Select v-model="rateType">
                    <SelectTrigger id="billableRateType">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="default-rate">Default Rate</SelectItem>
                        <SelectItem value="custom-rate">Custom Rate</SelectItem>
                    </SelectContent>
                </Select>
                <TooltipProvider v-if="rateType === 'default-rate'">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <div>
                                <BillableRateInput
                                    v-model="displayedRate"
                                    :currency="currency"
                                    disabled
                                    name="billableRate" />
                            </div>
                        </TooltipTrigger>
                        <TooltipContent> Uses the default rate of the organization </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
                <BillableRateInput
                    v-else
                    v-model="displayedRate"
                    :currency="currency"
                    name="billableRate"
                    @keydown.enter="emit('submit')" />
            </div>
        </Field>
    </template>
    <template v-else>
        <Field>
            <FieldLabel for="fixedPrice" :icon="BillableIcon">Fixed contract total</FieldLabel>
            <BillableRateInput
                id="fixedPrice"
                v-model="fixedPrice"
                :currency="currency"
                name="fixedPrice"
                @keydown.enter="emit('submit')" />
            <FieldDescription>
                Billable time on this project splits this amount in reports (same currency minor units as hourly
                rates).
            </FieldDescription>
        </Field>
        <Field>
            <FieldLabel for="amountReceived" :icon="BillableIcon">Amount received</FieldLabel>
            <BillableRateInput
                id="amountReceived"
                v-model="amountReceived"
                :currency="currency"
                name="amountReceived"
                @keydown.enter="emit('submit')" />
            <FieldDescription v-if="paymentReceivedPercentHint">
                {{ paymentReceivedPercentHint }}
            </FieldDescription>
            <FieldDescription v-else>
                Total cash collected so far toward this fixed contract (same minor units as the contract
                total).
            </FieldDescription>
        </Field>
    </template>
</template>

<style scoped></style>
