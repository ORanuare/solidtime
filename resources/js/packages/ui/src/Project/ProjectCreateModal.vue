<script setup lang="ts">
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { computed, ref, watch } from 'vue';
import type { CreateClientBody, CreateProjectBody, Project } from '@/packages/api/src';

type ProjectCreateForm = CreateProjectBody & { billing_type: 'hourly' | 'fixed' };
import { getRandomColor } from '@/packages/ui/src/utils/color';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { useFocus } from '@vueuse/core';
import ClientDropdown from '@/packages/ui/src/Client/ClientDropdown.vue';
import ProjectColorSelector from '@/packages/ui/src/Project/ProjectColorSelector.vue';
import { Button } from '@/packages/ui/src/Buttons';
import { ChevronDown } from 'lucide-vue-next';
import { UserCircleIcon } from '@heroicons/vue/20/solid';
import EstimatedTimeSection from '@/packages/ui/src/EstimatedTimeSection.vue';
import { Field, FieldGroup, FieldLabel } from '../field';
import ProjectEditBillableSection from '@/packages/ui/src/Project/ProjectEditBillableSection.vue';
import type { Client } from '@/packages/api/src';

const show = defineModel('show', { default: false });
const saving = ref(false);

const props = defineProps<{
    clients: Client[];
    createProject: (project: CreateProjectBody) => Promise<Project | undefined>;
    createClient: (client: CreateClientBody) => Promise<Client | undefined>;
    currency: string;
    /** Workspace currency options from API (includes default hourly rate minor units per currency). */
    workspaceCurrencies?: Array<{
        currency_code: string;
        currency_symbol?: string;
        is_primary?: boolean;
        default_billable_rate?: number | null;
    }>;
    enableEstimatedTime: boolean;
    organizationBillableRate: number | null;
    initialProjectName?: string;
}>();

const activeClients = computed(() => {
    return props.clients.filter((client) => !client.is_archived);
});

const project = ref<ProjectCreateForm>({
    name: props.initialProjectName ?? '',
    color: getRandomColor(),
    client_id: null,
    currency: props.currency,
    billable_rate: null,
    billing_type: 'hourly',
    fixed_price: null,
    amount_received: null,
    is_billable: false,
    estimated_time: null,
});

async function submit() {
    await props.createProject(project.value as CreateProjectBody);
    show.value = false;
    project.value = {
        name: '',
        color: getRandomColor(),
        client_id: null,
        currency: props.currency,
        billable_rate: null,
        billing_type: 'hourly',
        fixed_price: null,
        amount_received: null,
        is_billable: false,
        estimated_time: null,
    };
}

const projectNameInput = ref<HTMLInputElement | null>(null);

useFocus(projectNameInput, { initialValue: true });

const billableCurrency = computed(() => project.value.currency ?? props.currency);

/** Minor units matching project currency row; legacy prop is primary-currency-only. */
const organizationBillableRateForProjectCurrency = computed(() => {
    const code = billableCurrency.value;
    const hit = props.workspaceCurrencies?.find((c) => c.currency_code === code);
    if (hit !== undefined && 'default_billable_rate' in hit) {
        return hit.default_billable_rate ?? null;
    }
    return props.organizationBillableRate ?? null;
});

watch(show, (open) => {
    if (!open) {
        return;
    }
    const primary =
        props.workspaceCurrencies?.find((c) => c.is_primary)?.currency_code ?? props.currency;
    project.value.currency = primary;
});

const currentClientName = computed(() => {
    if (project.value.client_id) {
        return props.clients.find((client) => client.id === project.value.client_id)?.name;
    }
    return 'No Client';
});
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false">
        <template #title>
            <div class="flex space-x-2">
                <span> Create Project </span>
            </div>
        </template>

        <template #content>
            <FieldGroup>
                <FieldGroup class="flex-row items-end">
                    <Field class="w-auto text-center">
                        <FieldLabel for="color">Color</FieldLabel>
                        <ProjectColorSelector v-model="project.color"></ProjectColorSelector>
                    </Field>
                    <Field class="w-full">
                        <FieldLabel for="projectName">Project name</FieldLabel>
                        <TextInput
                            id="projectName"
                            ref="projectNameInput"
                            v-model="project.name"
                            name="projectName"
                            type="text"
                            placeholder="The next big thing"
                            class="block w-full"
                            required
                            autocomplete="projectName"
                            @keydown.enter="submit()" />
                    </Field>
                </FieldGroup>
                <Field>
                    <FieldLabel for="client" :icon="UserCircleIcon">Client</FieldLabel>
                    <ClientDropdown
                        v-model="project.client_id"
                        :create-client="createClient"
                        :clients="activeClients">
                        <template #trigger>
                            <Button variant="input" class="w-full justify-between">
                                <span class="truncate">{{ currentClientName }}</span>
                                <ChevronDown class="w-4 h-4 text-icon-default" />
                            </Button>
                        </template>
                    </ClientDropdown>
                </Field>
                <Field v-if="(workspaceCurrencies?.length ?? 0) > 0">
                    <FieldLabel for="projectCurrency">Currency</FieldLabel>
                    <select
                        id="projectCurrency"
                        v-model="project.currency"
                        class="block w-full rounded-md border-0 bg-input-background py-2 px-3 text-default-text shadow-input ring-default-border-sm focus:ring-2 focus:ring-brand-primary sm:text-sm">
                        <option
                            v-for="c in workspaceCurrencies ?? []"
                            :key="c.currency_code"
                            :value="c.currency_code">
                            {{ c.currency_code }}
                            {{ c.currency_symbol ? `(${c.currency_symbol})` : '' }}
                        </option>
                    </select>
                </Field>
                <ProjectEditBillableSection
                    v-model:is-billable="project.is_billable"
                    v-model:billable-rate="project.billable_rate"
                    v-model:billing-type="project.billing_type"
                    v-model:fixed-price="project.fixed_price"
                    v-model:amount-received="project.amount_received"
                    :currency="billableCurrency"
                    :organization-billable-rate="organizationBillableRateForProjectCurrency" />
                <EstimatedTimeSection
                    v-if="enableEstimatedTime"
                    v-model="project.estimated_time"
                    @submit="submit()"></EstimatedTimeSection>
            </FieldGroup>
        </template>
        <template #footer>
            <SecondaryButton @click="show = false"> Cancel</SecondaryButton>
            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving"
                @click="submit">
                Create Project
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
