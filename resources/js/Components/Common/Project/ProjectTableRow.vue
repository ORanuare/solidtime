<script setup lang="ts">
import ProjectMoreOptionsDropdown from '@/Components/Common/Project/ProjectMoreOptionsDropdown.vue';
import type { Project } from '@/packages/api/src';
import { computed, ref, inject, type ComputedRef } from 'vue';
import { CheckCircleIcon, ArchiveBoxIcon } from '@heroicons/vue/24/outline';
import {
    PencilSquareIcon,
    ArchiveBoxIcon as ArchiveBoxIconSolid,
    TrashIcon,
} from '@heroicons/vue/20/solid';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useProjectsStore } from '@/utils/useProjects';
import TableRow from '@/Components/TableRow.vue';
import ProjectFixedPaymentQuickEdit from '@/Components/Common/Project/ProjectFixedPaymentQuickEdit.vue';
import { formatCents } from '@/packages/ui/src/utils/money';
import { getOrganizationCurrencyString } from '@/utils/money';
import EstimatedTimeProgress from '@/packages/ui/src/EstimatedTimeProgress.vue';
import UpgradeBadge from '@/Components/Common/UpgradeBadge.vue';
import { formatHumanReadableDuration } from '../../../packages/ui/src/utils/time';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { canUpdateProjects, canDeleteProjects } from '@/utils/permissions';
import type { Organization } from '@/packages/api/src';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuTrigger,
} from '@/packages/ui/src';

const { clients } = useClientsQuery();
const { tasks } = useTasksQuery();

const props = defineProps<{
    project: Project;
    showBillableRate: boolean;
    showPerProjectBillableTotal?: boolean;
    /** Resolved cents for this project when totals are loaded. */
    projectBillableTotalCents?: number | null;
    projectBillableTotalsPending?: boolean;
}>();

const client = computed(() => {
    return clients.value.find((client) => client.id === props.project.client_id);
});

const projectTasksCount = computed(() => {
    return tasks.value.filter((task) => task.project_id === props.project.id).length;
});

function deleteProject() {
    useProjectsStore().deleteProject(props.project.id);
}

function archiveProject() {
    const p = props.project;
    useProjectsStore().updateProject(p.id, {
        name: p.name,
        color: p.color,
        client_id: p.client_id,
        is_billable: p.is_billable,
        billing_type: p.billing_type === 'fixed' ? 'fixed' : 'hourly',
        fixed_price: p.fixed_price ?? null,
        billable_rate: p.billable_rate ?? null,
        estimated_time: p.estimated_time ?? null,
        is_archived: !p.is_archived,
        is_public: p.is_public,
        amount_received: p.amount_received ?? null,
    });
}

const organization = inject<ComputedRef<Organization>>('organization');

type BillableBillingDisplay =
    | { kind: 'fixed_price'; amount: string }
    | { kind: 'fixed_unpriced' }
    | { kind: 'hourly_custom'; amount: string }
    | { kind: 'hourly_default'; amount: string };

const billableBillingDisplay = computed((): BillableBillingDisplay | null => {
    if (!props.project.is_billable) {
        return null;
    }
    const org = organization?.value;
    if (!org) {
        return null;
    }
    const fmt = (cents: number) =>
        formatCents(
            cents,
            getOrganizationCurrencyString(),
            org.currency_format,
            org.currency_symbol,
            org.number_format
        );
    if (props.project.billing_type === 'fixed') {
        if (props.project.fixed_price != null) {
            return { kind: 'fixed_price', amount: fmt(props.project.fixed_price) ?? '—' };
        }
        return { kind: 'fixed_unpriced' };
    }
    if (props.project.billable_rate) {
        return { kind: 'hourly_custom', amount: fmt(props.project.billable_rate) ?? '—' };
    }
    const defaultCents = org.billable_rate;
    return {
        kind: 'hourly_default',
        amount: defaultCents != null ? (fmt(defaultCents) ?? '—') : '—',
    };
});

const showEditProjectModal = ref(false);

/** Fixed contract + payment widget share one card (no duplicate amount above it). */
const billingShowsUnifiedPaymentCard = computed(
    () =>
        props.showBillableRate &&
        props.project.billing_type === 'fixed' &&
        props.project.is_billable &&
        props.project.fixed_price != null &&
        props.project.fixed_price > 0
);

const projectBillableTotalFormatted = computed(() => {
    if (!props.showPerProjectBillableTotal || props.projectBillableTotalsPending) {
        return null;
    }
    const org = organization?.value;
    if (!org) {
        return null;
    }
    const cents = props.projectBillableTotalCents ?? 0;
    return formatCents(
        cents,
        getOrganizationCurrencyString(),
        org.currency_format,
        org.currency_symbol,
        org.number_format
    );
});
</script>

<template>
    <ContextMenu>
        <ContextMenuTrigger as-child>
            <TableRow :href="route('projects.show', { project: project.id })">
                <div
                    class="whitespace-nowrap min-w-0 flex items-center space-x-5 3xl:pl-12 py-4 pr-3 text-sm font-medium text-text-primary pl-4 sm:pl-6 lg:pl-8 3xl:pl-12">
                    <div
                        :style="{
                            backgroundColor: project.color,
                            boxShadow: `var(--tw-ring-inset) 0 0 0 calc(4px + var(--tw-ring-offset-width)) ${project.color}30`,
                        }"
                        class="w-3 h-3 rounded-full"></div>
                    <span class="overflow-ellipsis overflow-hidden">
                        {{ project.name }}
                    </span>
                    <span class="text-text-secondary"> {{ projectTasksCount }} Tasks </span>
                </div>
                <div
                    class="min-w-0 px-3 py-4 text-sm text-text-primary flex items-center whitespace-nowrap">
                    <div v-if="project.client_id" class="overflow-ellipsis overflow-hidden min-w-0">
                        {{ client?.name }}
                    </div>
                    <div v-else class="text-text-tertiary">No client</div>
                </div>
                <div
                    class="min-w-0 px-3 py-4 text-sm text-text-primary font-medium flex items-center whitespace-nowrap"
                    :title="
                        project.client_id
                            ? 'Has a linked client'
                            : 'No client (internal / house project)'
                    ">
                    <span v-if="project.client_id">Client</span>
                    <span v-else class="text-text-secondary">Internal</span>
                </div>
                <div
                    class="px-3 py-4 text-sm text-text-primary flex items-center whitespace-nowrap">
                    <div v-if="project.spent_time">
                        {{
                            formatHumanReadableDuration(
                                project.spent_time,
                                organization?.interval_format,
                                organization?.number_format
                            )
                        }}
                    </div>
                    <div v-else class="text-text-tertiary">--</div>
                </div>
                <div
                    v-if="showPerProjectBillableTotal"
                    class="px-3 py-4 text-sm text-text-primary flex items-center whitespace-nowrap">
                    <span v-if="projectBillableTotalFormatted">{{ projectBillableTotalFormatted }}</span>
                    <span v-else class="text-text-tertiary">—</span>
                </div>
                <div
                    class="min-w-0 px-3 py-4 text-sm text-text-primary flex items-center whitespace-nowrap">
                    <UpgradeBadge v-if="!isAllowedToPerformPremiumAction()"></UpgradeBadge>
                    <EstimatedTimeProgress
                        v-else-if="project.estimated_time"
                        :estimated="project.estimated_time"
                        :current="project.spent_time"></EstimatedTimeProgress>
                    <span v-else class="text-text-tertiary"> -- </span>
                </div>
                <div
                    v-if="showBillableRate"
                    class="min-w-0 px-3 py-4 text-sm text-text-primary flex items-center">
                    <div class="flex flex-col gap-1 items-stretch min-w-0 w-full">
                        <div v-if="!billingShowsUnifiedPaymentCard" class="whitespace-nowrap">
                            <template v-if="billableBillingDisplay">
                                <template v-if="billableBillingDisplay.kind === 'fixed_price'">
                                    {{ billableBillingDisplay.amount }}
                                </template>
                                <template v-else-if="billableBillingDisplay.kind === 'fixed_unpriced'">
                                    Fixed
                                </template>
                                <template v-else-if="billableBillingDisplay.kind === 'hourly_custom'">
                                    {{ billableBillingDisplay.amount
                                    }}<span class="text-text-secondary"> / h</span>
                                </template>
                                <template v-else-if="billableBillingDisplay.kind === 'hourly_default'">
                                    {{ billableBillingDisplay.amount
                                    }}<span class="text-text-secondary"> / h</span>
                                </template>
                            </template>
                            <span v-else class="text-text-tertiary">--</span>
                        </div>
                        <ProjectFixedPaymentQuickEdit
                            :project="project"
                            :show-billable-rate="showBillableRate"
                            variant="table" />
                    </div>
                </div>
                <div
                    class="whitespace-nowrap px-3 py-4 text-sm text-text-primary flex space-x-1.5 items-center font-medium">
                    <template v-if="project.is_archived">
                        <ArchiveBoxIcon class="w-4 text-icon-default"></ArchiveBoxIcon>
                        <span>Archived</span>
                    </template>
                    <template v-else>
                        <CheckCircleIcon class="w-4 text-icon-default"></CheckCircleIcon>
                        <span>Active</span>
                    </template>
                </div>
                <div
                    class="relative whitespace-nowrap flex items-center py-4 pl-3 text-right text-sm font-medium pr-4 sm:pr-6 lg:pr-8 3xl:pr-12">
                    <ProjectEditModal
                        v-model:show="showEditProjectModal"
                        :original-project="project"></ProjectEditModal>
                    <ProjectMoreOptionsDropdown
                        :project="project"
                        @edit="showEditProjectModal = true"
                        @archive="archiveProject"
                        @delete="deleteProject"></ProjectMoreOptionsDropdown>
                </div>
            </TableRow>
        </ContextMenuTrigger>
        <ContextMenuContent class="min-w-[160px]">
            <ContextMenuItem
                v-if="canUpdateProjects()"
                class="space-x-3"
                @select="showEditProjectModal = true">
                <PencilSquareIcon class="w-4 h-4 text-icon-default" />
                <span>Edit</span>
            </ContextMenuItem>
            <ContextMenuItem
                v-if="canUpdateProjects()"
                class="space-x-3"
                @select="archiveProject()">
                <ArchiveBoxIconSolid class="w-4 h-4 text-icon-default" />
                <span>{{ project.is_archived ? 'Unarchive' : 'Archive' }}</span>
            </ContextMenuItem>
            <ContextMenuSeparator v-if="canDeleteProjects()" />
            <ContextMenuItem
                v-if="canDeleteProjects()"
                class="space-x-3 text-destructive"
                @select="deleteProject()">
                <TrashIcon class="w-4 h-4 text-icon-default" />
                <span>Delete</span>
            </ContextMenuItem>
        </ContextMenuContent>
    </ContextMenu>
</template>

<style scoped></style>
