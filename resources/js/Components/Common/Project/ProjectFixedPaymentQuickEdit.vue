<script setup lang="ts">
import type { ComputedRef } from 'vue';
import { computed, inject, ref, watch } from 'vue';
import type { Organization, Project, UpdateProjectBody } from '@/packages/api/src';
import { Popover, PopoverContent, PopoverTrigger } from '@/packages/ui/src';
import BillableRateInput from '@/packages/ui/src/Input/BillableRateInput.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { Field, FieldDescription, FieldLabel } from '@/packages/ui/src/field';
import { useProjectsStore } from '@/utils/useProjects';
import { canUpdateProjects } from '@/utils/permissions';
import { formatCents, getOrganizationCurrencySymbol } from '@/packages/ui/src/utils/money';

const props = defineProps<{
    project: Project;
    showBillableRate: boolean;
    /** Table row vs project detail header (same compact UI; header aligns with badges). */
    variant: 'table' | 'header';
}>();

const organization = inject<ComputedRef<Organization>>('organization');

const open = ref(false);
const saving = ref(false);
const amountDraft = ref(0);

const { updateProject } = useProjectsStore();

const isEligible = computed(
    () =>
        props.showBillableRate &&
        props.project.billing_type === 'fixed' &&
        props.project.is_billable &&
        props.project.fixed_price != null &&
        props.project.fixed_price > 0
);

const canEdit = computed(() => canUpdateProjects() && isEligible.value);

const fixedPrice = computed(() => props.project.fixed_price ?? 0);

/** Fixed-price payment progress is always tracked in the UI; null stored amount displays as zero until saved. */
const effectiveAmountReceived = computed(() => props.project.amount_received ?? 0);

const displayPercent = computed(() => {
    if (fixedPrice.value <= 0) {
        return 0;
    }

    return Math.min(100, Math.round((effectiveAmountReceived.value / fixedPrice.value) * 100));
});

function syncDraftsFromProject() {
    amountDraft.value = props.project.amount_received ?? 0;
}

watch(open, (isOpen) => {
    if (isOpen) {
        syncDraftsFromProject();
    }
});

watch(
    () => props.project.id,
    () => {
        if (!open.value) {
            syncDraftsFromProject();
        }
    }
);

watch(
    () => [props.project.amount_received, props.project.fixed_price],
    () => {
        if (!open.value) {
            syncDraftsFromProject();
        }
    }
);

function clampAmount(n: number): number {
    const max = fixedPrice.value;

    return Math.min(Math.max(0, Math.round(n)), max);
}

const percentForRange = computed({
    get() {
        if (fixedPrice.value <= 0) {
            return 0;
        }

        return Math.min(100, Math.round((amountDraft.value / fixedPrice.value) * 100));
    },
    set(v: number | string) {
        const n = typeof v === 'string' ? Number(v) : v;
        const bounded = Number.isFinite(n) ? Math.min(100, Math.max(0, n)) : 0;
        amountDraft.value = clampAmount(Math.round((bounded / 100) * fixedPrice.value));
    },
});

const barPercentDisplay = computed(() => {
    if (fixedPrice.value <= 0) {
        return 0;
    }

    return Math.min(100, (effectiveAmountReceived.value / fixedPrice.value) * 100);
});

function fmt(cents: number | null): string {
    const org = organization?.value;
    if (cents == null || !org) {
        return '—';
    }

    const iso = props.project.currency;

    return (
        formatCents(
            cents,
            iso,
            org.currency_format,
            getOrganizationCurrencySymbol(iso),
            org.number_format
        ) ?? '—'
    );
}

function buildUpdateBody(overrides: Partial<UpdateProjectBody>): UpdateProjectBody {
    const p = props.project;

    return {
        name: p.name,
        color: p.color,
        client_id: p.client_id,
        is_billable: p.is_billable,
        billing_type: p.billing_type === 'fixed' ? 'fixed' : 'hourly',
        fixed_price: p.fixed_price ?? null,
        billable_rate: p.billable_rate ?? null,
        estimated_time: p.estimated_time ?? null,
        is_archived: p.is_archived,
        is_public: p.is_public,
        amount_received: p.amount_received ?? null,
        ...overrides,
    };
}

async function save() {
    saving.value = true;
    try {
        await updateProject(
            props.project.id,
            buildUpdateBody({
                amount_received: clampAmount(amountDraft.value),
            })
        );
        open.value = false;
    } finally {
        saving.value = false;
    }
}

const rootStopClass = computed(() =>
    props.variant === 'header' ? 'inline-flex' : 'flex flex-col gap-1.5'
);

const triggerButtonClass = computed(() => {
    const shared =
        'group/trigger rounded text-left outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring';
    if (props.variant === 'header') {
        return `${shared} inline-flex flex-col gap-1 align-middle border border-input-border py-1 px-2 hover:bg-tertiary/40`;
    }

    return `${shared} w-full min-w-0 px-1 py-0.5 -mx-1 hover:bg-white/[0.06]`;
});

const readonlyOuterClass = computed(() =>
    props.variant === 'header'
        ? 'inline-flex flex-col gap-1 rounded border border-input-border py-1 px-2'
        : 'w-full min-w-0 py-0.5'
);
</script>

<template>
    <!-- Stop row navigation where parent uses TableRow / Link (table variant). -->
    <div v-if="isEligible" :class="rootStopClass" @click.stop @mousedown.stop>
        <Popover v-if="canEdit" v-model:open="open">
            <PopoverTrigger as-child>
                <button type="button" :class="triggerButtonClass" title="Edit amount received" @click.stop>
                    <span
                        class="text-sm tabular-nums text-text-primary leading-tight break-words whitespace-normal">
                        {{ fmt(project.fixed_price) }}
                    </span>
                    <div class="flex items-center gap-2 min-w-0">
                        <div class="h-1 min-w-[3rem] flex-1 rounded-full bg-tertiary overflow-hidden">
                            <div
                                class="h-full rounded-full bg-accent-200 transition-[width]"
                                :style="{ width: `${barPercentDisplay}%` }"></div>
                        </div>
                        <span class="text-[11px] tabular-nums text-text-secondary shrink-0">
                            {{ displayPercent }}%
                        </span>
                    </div>
                </button>
            </PopoverTrigger>
            <PopoverContent
                class="w-[min(22rem,calc(100vw-2rem))] p-4 sm:p-5"
                align="start"
                @click.stop>
                <div class="space-y-4">
                    <div>
                        <h4 class="text-sm font-semibold text-text-primary">Payment progress</h4>
                        <p class="text-xs text-text-secondary mt-1">
                            Contract total {{ fmt(project.fixed_price) }}. Adjust how much has been
                            collected.
                        </p>
                    </div>
                    <Field>
                        <FieldLabel>Percent received</FieldLabel>
                        <input
                            v-model.number="percentForRange"
                            type="range"
                            min="0"
                            max="100"
                            step="1"
                            class="w-full accent-accent-200 h-2 mt-2" />
                        <FieldDescription>{{ percentForRange }}% of contract</FieldDescription>
                    </Field>
                    <Field>
                        <FieldLabel>Amount received</FieldLabel>
                        <BillableRateInput
                            v-model="amountDraft"
                            :currency="project.currency"
                            name="quickPaymentAmount" />
                    </Field>
                    <div class="flex justify-end gap-2 pt-1">
                        <SecondaryButton type="button" @click="open = false">Cancel</SecondaryButton>
                        <PrimaryButton type="button" :disabled="saving" @click="save">
                            Save
                        </PrimaryButton>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
        <div v-else :class="readonlyOuterClass">
            <span class="text-sm tabular-nums text-text-primary leading-tight break-words whitespace-normal">
                {{ fmt(project.fixed_price) }}
            </span>
            <div class="flex items-center gap-2 min-w-0">
                <div class="h-1 min-w-[3rem] flex-1 rounded-full bg-tertiary overflow-hidden">
                    <div
                        class="h-full rounded-full bg-accent-200 transition-[width]"
                        :style="{ width: `${barPercentDisplay}%` }"></div>
                </div>
                <span class="text-[11px] tabular-nums text-text-secondary shrink-0">
                    {{ displayPercent }}%
                </span>
            </div>
        </div>
    </div>
</template>
