<script setup lang="ts">
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { UserCircleIcon, ChevronRightIcon, FolderIcon } from '@heroicons/vue/20/solid';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { ArrowRightIcon, PencilSquareIcon } from '@heroicons/vue/20/solid';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useStorage } from '@vueuse/core';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { getCurrentOrganizationId, getCurrentRole, getCurrentMembershipId } from '@/utils/useUser';
import {
    canCreateProjects,
    canUpdateClients,
    canViewAllTimeEntries,
} from '@/utils/permissions';
import CardTitle from '@/packages/ui/src/CardTitle.vue';
import ProjectTable from '@/Components/Common/Project/ProjectTable.vue';
import ProjectsFilterDropdown from '@/Components/Common/Project/ProjectsFilterDropdown.vue';
import ProjectStatusFilterBadge from '@/Components/Common/Project/ProjectStatusFilterBadge.vue';
import type { ProjectFilters } from '@/Components/Common/Project/ProjectsFilterDropdown.vue';
import type { SortColumn, SortDirection } from '@/Components/Common/Project/ProjectTable.vue';
import ClientEditModal from '@/Components/Common/Client/ClientEditModal.vue';
import ClientMoreOptionsDropdown from '@/Components/Common/Client/ClientMoreOptionsDropdown.vue';
import { useClientsStore } from '@/utils/useClients';
import { api } from '@/packages/api/src';
import { useQuery } from '@tanstack/vue-query';
import { formatCents } from '@/packages/ui/src/utils/money';
import { getOrganizationCurrencyString } from '@/utils/money';
import { formatHumanReadableDuration } from '@/packages/ui/src/utils/time';
import { ArchiveBoxIcon, CheckCircleIcon } from '@heroicons/vue/24/outline';
import { LoadingSpinner } from '@/packages/ui/src';

const { clients, isFetching, isFetched } = useClientsQuery();
const { projects } = useProjectsQuery();
const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);

const orgForFormat = computed(() => organization.value ?? null);

const clientId = computed(() => (route().params?.client as string) ?? '');

const client = computed(() => clients.value.find((c) => c.id === clientId.value) ?? null);

const allProjectsForClient = computed(() => {
    return projects.value.filter((p) => p.client_id === clientId.value);
});

const clientProjectFilters = useStorage<ProjectFilters>(
    'client-detail-project-filters',
    { status: 'active', clientIds: [] },
    undefined,
    { mergeDefaults: true }
);

const filteredProjectsForClient = computed(() => {
    const status = clientProjectFilters.value.status;
    return allProjectsForClient.value.filter((project) => {
        if (status === 'active' && project.is_archived) {
            return false;
        }
        if (status === 'archived' && !project.is_archived) {
            return false;
        }
        return true;
    });
});

function removeClientProjectsStatusFilter() {
    clientProjectFilters.value.status = 'active';
}

function onClientProjectFiltersUpdate(filters: ProjectFilters) {
    clientProjectFilters.value = { ...filters, clientIds: [] };
}

interface ClientDetailProjectTableSort {
    sortColumn: SortColumn;
    sortDirection: SortDirection;
}

const projectTableSort = useStorage<ClientDetailProjectTableSort>(
    'client-detail-project-table-sort',
    {
        sortColumn: 'name',
        sortDirection: 'asc',
    },
    undefined,
    { mergeDefaults: true }
);

watch(
    () => projectTableSort.value.sortColumn,
    (col) => {
        if ((col as string) === 'is_paid') {
            projectTableSort.value.sortColumn = 'has_client';
        }
    },
    { immediate: true }
);

function handleProjectSort(column: SortColumn, direction: SortDirection) {
    projectTableSort.value.sortColumn = column;
    projectTableSort.value.sortDirection = direction;
}

/** Same rule as the main Projects page. */
const showBillableRate = computed(() => {
    return !!(
        getCurrentRole() !== 'employee' || organization.value?.employees_can_see_billable_rates
    );
});

const notFound = computed(() => isFetched.value && !client.value && !!clientId.value);

const showEditModal = ref(false);

const canFetchAggregate = computed(() => {
    if (!getCurrentOrganizationId() || !clientId.value) {
        return false;
    }
    return canViewAllTimeEntries() || getCurrentRole() === 'employee';
});

const showBillableCost = computed(() => {
    return (
        getCurrentRole() !== 'employee' ||
        orgForFormat.value?.employees_can_see_billable_rates
    );
});

const aggregateQueries = computed(() => {
    const org = getCurrentOrganizationId();
    if (!org || !clientId.value) {
        return null;
    }
    const q: { client_ids: string[]; member_id?: string } = { client_ids: [clientId.value] };
    if (getCurrentRole() === 'employee') {
        const mid = getCurrentMembershipId();
        if (mid) {
            q.member_id = mid;
        }
    }
    return q;
});

const { data: aggregateResult } = useQuery({
    queryKey: computed(() => [
        'client-billable-aggregate',
        getCurrentOrganizationId(),
        clientId.value,
        getCurrentRole(),
        getCurrentMembershipId(),
    ]),
    queryFn: () =>
        api.getAggregatedTimeEntries({
            params: { organization: getCurrentOrganizationId()! },
            queries: aggregateQueries.value!,
        }),
    enabled: computed(
        () => canFetchAggregate.value && aggregateQueries.value !== null && !notFound.value
    ),
    staleTime: 30_000,
});

const { data: projectBillableByProjectResult, isPending: projectBillableTotalsPending } = useQuery({
    queryKey: computed(() => [
        'client-billable-by-project',
        getCurrentOrganizationId(),
        clientId.value,
        getCurrentRole(),
        getCurrentMembershipId(),
    ]),
    queryFn: () =>
        api.getAggregatedTimeEntries({
            params: { organization: getCurrentOrganizationId()! },
            queries: {
                ...aggregateQueries.value!,
                group: 'project',
            },
        }),
    enabled: computed(
        () =>
            showBillableCost.value &&
            canFetchAggregate.value &&
            aggregateQueries.value !== null &&
            !notFound.value
    ),
    staleTime: 30_000,
});

const perProjectBillableCentsById = computed(() => {
    if (!showBillableCost.value) {
        return null;
    }
    if (projectBillableTotalsPending.value) {
        return null;
    }
    const groups = projectBillableByProjectResult.value?.data?.grouped_data;
    if (!groups) {
        return {};
    }
    const map: Record<string, number> = {};
    for (const row of groups) {
        if (row.key) {
            map[row.key] = row.cost ?? 0;
        }
    }
    return map;
});

const totalCostCents = computed(() => aggregateResult.value?.data?.cost);
const totalSeconds = computed(() => aggregateResult.value?.data?.seconds ?? 0);

const costFormatted = computed(() => {
    if (!showBillableCost.value) {
        return null;
    }
    if (totalCostCents.value === null || totalCostCents.value === undefined) {
        return null;
    }
    const o = orgForFormat.value;
    if (!o) {
        return null;
    }
    return formatCents(
        totalCostCents.value,
        getOrganizationCurrencyString(),
        o.currency_format,
        o.currency_symbol,
        o.number_format
    );
});

const durationFormatted = computed(() => {
    const o = orgForFormat.value;
    if (!o) {
        return null;
    }
    if (!totalSeconds.value) {
        return null;
    }
    return formatHumanReadableDuration(
        totalSeconds.value,
        o.interval_format,
        o.number_format
    );
});

function deleteClient() {
    if (client.value) {
        useClientsStore().deleteClient(client.value.id);
    }
}

function archiveClient() {
    if (!client.value) {
        return;
    }
    useClientsStore().updateClient(client.value.id, {
        ...client.value,
        is_archived: !client.value.is_archived,
    });
}
</script>

<template>
    <AppLayout title="Clients" data-testid="client_show_view">
        <template v-if="isFetching && !isFetched">
            <MainContainer class="py-10 flex justify-center">
                <LoadingSpinner />
            </MainContainer>
        </template>
        <template v-else-if="notFound">
            <MainContainer class="py-10">
                <p class="text-text-primary font-medium">Client not found.</p>
                <Link
                    :href="route('clients')"
                    class="text-sm text-text-secondary hover:text-text-primary mt-2 inline-block">
                    Back to Clients
                </Link>
            </MainContainer>
        </template>
        <template v-else-if="client">
            <MainContainer
                class="py-5 border-b border-default-background-separator flex justify-between items-center">
                <nav class="flex flex-wrap items-center gap-2" aria-label="Breadcrumb">
                    <ol role="list" class="flex items-center space-x-2 min-w-0">
                        <li>
                            <div class="flex items-center space-x-6 min-w-0">
                                <Link
                                    :href="route('clients')"
                                    class="flex items-center space-x-2 sm:space-x-2.5 min-w-0">
                                    <UserCircleIcon class="w-5 h-5 shrink-0 text-icon-default" />
                                    <span class="text-sm sm:text-base font-medium">Clients</span>
                                </Link>
                            </div>
                        </li>
                        <li class="flex items-center min-w-0 text-text-primary font-semibold text-base">
                            <ChevronRightIcon
                                class="h-5 w-5 flex-shrink-0 text-text-secondary"
                                aria-hidden="true" />
                            <span class="ml-2 truncate max-w-[min(100%,24rem)]">{{ client.name }}</span>
                        </li>
                    </ol>
                    <div
                        v-if="client.is_archived"
                        class="flex items-center gap-1.5 text-sm text-text-tertiary">
                        <ArchiveBoxIcon class="w-4 h-4" />
                        <span>Archived</span>
                    </div>
                    <div v-else class="flex items-center gap-1.5 text-sm text-text-tertiary">
                        <CheckCircleIcon class="w-4 h-4" />
                        <span>Active</span>
                    </div>
                </nav>
                <div class="flex items-center gap-2 shrink-0" @click.stop>
                    <SecondaryButton
                        v-if="canUpdateClients()"
                        :icon="PencilSquareIcon"
                        @click="showEditModal = true">
                        Edit
                    </SecondaryButton>
                    <ClientEditModal v-model:show="showEditModal" :client="client" />
                    <ClientMoreOptionsDropdown
                        :client="client"
                        @edit="showEditModal = true"
                        @archive="archiveClient"
                        @delete="deleteClient" />
                </div>
            </MainContainer>

            <MainContainer class="pt-6 pb-4">
                <div class="grid gap-6 lg:grid-cols-2">
                    <div>
                        <h2 class="text-sm font-semibold text-text-tertiary uppercase tracking-wide mb-2">
                            Description
                        </h2>
                        <p
                            v-if="client.description"
                            class="text-sm text-text-primary whitespace-pre-wrap break-words">
                            {{ client.description }}
                        </p>
                        <p v-else class="text-sm text-text-tertiary">—</p>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold text-text-tertiary uppercase tracking-wide mb-2">
                            Contacts
                        </h2>
                        <dl
                            v-if="client.contacts.length"
                            class="space-y-2 text-sm text-text-primary">
                            <div
                                v-for="(c, i) in client.contacts"
                                :key="i"
                                class="flex flex-col sm:flex-row sm:gap-2 sm:items-baseline">
                                <dt class="font-medium text-text-secondary shrink-0">{{ c.label }}</dt>
                                <dd class="min-w-0 break-words">{{ c.value }}</dd>
                            </div>
                        </dl>
                        <p v-else class="text-sm text-text-tertiary">—</p>
                    </div>
                </div>
            </MainContainer>

            <MainContainer v-if="canFetchAggregate" class="pt-2 pb-6">
                <div
                    class="rounded-lg border border-card-border bg-card-background px-4 py-4 sm:px-6 sm:flex sm:items-baseline sm:justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-semibold text-text-tertiary uppercase tracking-wide">
                            Billable amount (all time)
                        </h2>
                        <p class="text-xs text-text-tertiary mt-1">
                            From tracked billable time for this client, using the same rules as
                            reporting.
                        </p>
                    </div>
                    <div class="mt-3 sm:mt-0 text-right sm:text-left">
                        <p v-if="showBillableCost && costFormatted" class="text-lg font-semibold text-text-primary">
                            {{ costFormatted }}
                        </p>
                        <p v-else-if="!showBillableCost" class="text-sm text-text-tertiary">—</p>
                        <p v-else class="text-sm text-text-tertiary">—</p>
                        <p v-if="durationFormatted" class="text-sm text-text-secondary mt-0.5">
                            {{ durationFormatted }} tracked
                        </p>
                    </div>
                </div>
            </MainContainer>

            <MainContainer class="pt-2 pb-10">
                <CardTitle title="Projects" :icon="FolderIcon">
                    <template #actions>
                        <SecondaryButton
                            v-if="canCreateProjects()"
                            :icon="ArrowRightIcon"
                            @click="router.visit(route('projects'))">
                            Go to projects
                        </SecondaryButton>
                    </template>
                </CardTitle>
                <div class="flex flex-wrap items-center gap-2 py-1 mt-2">
                    <ProjectsFilterDropdown
                        :filters="clientProjectFilters"
                        :clients="[]"
                        :show-client-filter="false"
                        @update:filters="onClientProjectFiltersUpdate" />
                    <ProjectStatusFilterBadge
                        v-if="clientProjectFilters.status !== 'active'"
                        :value="clientProjectFilters.status"
                        @remove="removeClientProjectsStatusFilter"
                        @update:value="
                            clientProjectFilters.status = $event as 'active' | 'archived' | 'all'
                        " />
                </div>
                <div v-if="filteredProjectsForClient.length > 0" class="mt-3">
                    <ProjectTable
                        :projects="filteredProjectsForClient"
                        :show-billable-rate="showBillableRate"
                        :show-per-project-billable-total="showBillableCost"
                        :per-project-billable-cents-by-id="perProjectBillableCentsById"
                        :sort-column="projectTableSort.sortColumn"
                        :sort-direction="projectTableSort.sortDirection"
                        @sort="handleProjectSort" />
                </div>
                <p
                    v-else-if="allProjectsForClient.length === 0"
                    class="mt-3 py-8 px-4 text-sm text-text-tertiary text-center rounded-lg border border-card-border bg-card-background">
                    No projects linked to this client yet.
                </p>
                <p
                    v-else
                    class="mt-3 py-8 px-4 text-sm text-text-tertiary text-center rounded-lg border border-card-border bg-card-background">
                    <template v-if="clientProjectFilters.status === 'active'">
                        No active projects for this client. Use the filter to show archived or all
                        projects.
                    </template>
                    <template v-else-if="clientProjectFilters.status === 'archived'">
                        No archived projects for this client.
                    </template>
                    <template v-else>No projects match the current filter.</template>
                </p>
            </MainContainer>
        </template>
    </AppLayout>
</template>
