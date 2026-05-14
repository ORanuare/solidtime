<script setup lang="ts">
import ReportingRow from '@/Components/Common/Reporting/ReportingRow.vue';
import ReportingGroupBySelect from '@/Components/Common/Reporting/ReportingGroupBySelect.vue';
import {
    formatReportingDuration,
    getDayJsInstance,
    getLocalizedDayJs,
} from '@/packages/ui/src/utils/time';
import { formatBillableMinorForIso } from '@/utils/formatBillableDisplay';
import { formatCents } from '@/packages/ui/src/utils/money';
import { getOrganizationCurrencyString } from '@/utils/money';
import { type GroupingOption, useReportingStore } from '@/utils/useReporting';
import { getCurrentMembershipId, getCurrentOrganizationId, getCurrentRole } from '@/utils/useUser';
import { useDashboardWeekOffset } from '@/utils/useDashboardWeekOffset';
import {
    api,
    type AggregatedTimeEntries,
    type AggregatedTimeEntriesQueryParams,
    type Organization,
} from '@/packages/api/src';
import { useQuery } from '@tanstack/vue-query';
import { useStorage } from '@vueuse/core';
import { computed, inject, type ComputedRef, watch } from 'vue';

const organization = inject<ComputedRef<Organization>>('organization');

const group = useStorage<GroupingOption>('dashboard-reporting-group', 'project');
const subGroup = useStorage<GroupingOption>('dashboard-reporting-sub-group', 'task');

const reportingStore = useReportingStore();
const { groupByOptions, getNameForReportingRowEntry } = reportingStore;

watch(
    group,
    () => {
        if (group.value === subGroup.value) {
            const fallbackOption = groupByOptions.find((el) => el.value !== group.value);
            if (fallbackOption?.value) {
                subGroup.value = fallbackOption.value;
            }
        }
    },
    { immediate: true }
);

const organizationId = computed(() => getCurrentOrganizationId());

const { weekOffset } = useDashboardWeekOffset();

const weekStartUtc = computed(() => {
    return getLocalizedDayJs(getDayJsInstance()().format())
        .startOf('week')
        .add(weekOffset.value, 'week')
        .startOf('day')
        .utc()
        .format();
});

const weekEndUtc = computed(() => {
    return getLocalizedDayJs(getDayJsInstance()().format())
        .startOf('week')
        .add(weekOffset.value, 'week')
        .endOf('week')
        .endOf('day')
        .utc()
        .format();
});

const queryParams = computed<AggregatedTimeEntriesQueryParams>(() => {
    return {
        start: weekStartUtc.value,
        end: weekEndUtc.value,
        group: group.value,
        sub_group: subGroup.value,
        member_id: getCurrentRole() === 'employee' ? getCurrentMembershipId() : undefined,
    };
});

const { data: reportingResponse, isLoading, isFetching } = useQuery({
    queryKey: [
        'dashboardThisWeekReporting',
        organizationId,
        weekOffset,
        weekStartUtc,
        weekEndUtc,
        group,
        subGroup,
    ],
    queryFn: () => {
        return api.getAggregatedTimeEntries({
            params: {
                organization: organizationId.value!,
            },
            queries: queryParams.value,
        });
    },
    enabled: computed(() => !!organizationId.value),
    placeholderData: (previousData) => previousData,
});

const aggregatedTableTimeEntries = computed<AggregatedTimeEntries | null>(() => {
    return (reportingResponse.value?.data as AggregatedTimeEntries | undefined) ?? null;
});

const weekBillableTotalsByCurrency = computed(
    () => aggregatedTableTimeEntries.value?.billable_totals_by_currency
);

const weekBillableTotalLines = computed(() => {
    const rows = weekBillableTotalsByCurrency.value;
    const org = organization?.value;
    if (!rows?.length || !org) {
        return [];
    }
    return rows.map((r) => formatBillableMinorForIso(r.minor_units, r.currency_code, org)).filter(Boolean) as string[];
});

const tableData = computed(() => {
    return (
        aggregatedTableTimeEntries.value?.grouped_data?.map((entry) => {
            const typedEntry = entry as { currency_code?: string };
            return {
                seconds: entry.seconds,
                cost: entry.cost,
                currency_code: typedEntry.currency_code,
                description: getNameForReportingRowEntry(
                    entry.key,
                    aggregatedTableTimeEntries.value?.grouped_type ?? null
                ),
                grouped_data:
                    entry.grouped_data?.map((el) => {
                        const typedSub = el as { currency_code?: string };
                        return {
                            seconds: el.seconds,
                            cost: el.cost,
                            currency_code: typedSub.currency_code ?? typedEntry.currency_code,
                            description: getNameForReportingRowEntry(
                                el.key,
                                entry.grouped_type ?? null
                            ),
                        };
                    }) ?? [],
            };
        }) ?? []
    );
});

const showBillableRate = computed(() => {
    return !!(
        getCurrentRole() !== 'employee' || organization?.value?.employees_can_see_billable_rates
    );
});

const isTableRefetching = computed(() => isFetching.value && !isLoading.value);
</script>

<template>
    <div class="rounded-lg bg-card-background border border-card-border">
        <div
            class="text-sm flex text-text-primary pt-3 items-center space-x-3 font-medium px-6 border-b border-card-background-separator pb-3">
            <span>Group by</span>
            <ReportingGroupBySelect
                v-model="group"
                :group-by-options="groupByOptions"></ReportingGroupBySelect>
            <span>and</span>
            <ReportingGroupBySelect
                v-model="subGroup"
                :group-by-options="
                    groupByOptions.filter((el) => el.value !== group)
                "></ReportingGroupBySelect>
        </div>

        <div
            class="grid items-stretch relative transition-opacity duration-200"
            :class="isTableRefetching ? 'opacity-60' : 'opacity-100'"
            :style="`grid-template-columns: 1fr 100px ${showBillableRate ? '150px' : ''}`">
            <div
                v-if="isTableRefetching"
                class="absolute right-4 top-1 text-xs text-text-tertiary z-10"
                role="status"
                aria-live="polite">
                Updating…
            </div>
            <div
                class="contents [&>*]:border-card-background-separator [&>*]:border-b [&>*]:pb-1.5 [&>*]:pt-1 text-text-tertiary text-sm">
                <div class="pl-6">Name</div>
                <div class="text-right" :class="!showBillableRate ? 'pr-6' : ''">Duration</div>
                <div v-if="showBillableRate" class="text-right pr-6">Cost</div>
            </div>

            <div
                v-if="isLoading"
                class="flex justify-center py-10 text-text-tertiary"
                :class="showBillableRate ? 'col-span-3' : 'col-span-2'">
                Loading reporting data…
            </div>

            <template
                v-else-if="
                    aggregatedTableTimeEntries?.grouped_data &&
                    aggregatedTableTimeEntries.grouped_data.length > 0
                ">
                <ReportingRow
                    v-for="entry in tableData"
                    :key="entry.description ?? 'none'"
                    :currency="getOrganizationCurrencyString()"
                    :show-cost="showBillableRate"
                    :entry="entry"></ReportingRow>
                <div class="contents [&>*]:transition text-text-tertiary [&>*]:min-h-[50px] [&>*]:py-2 box-border">
                    <div class="flex items-center pl-6 font-medium">
                        <span>Total</span>
                    </div>
                    <div
                        class="justify-end flex items-center font-medium"
                        :class="!showBillableRate ? 'pr-6' : ''">
                        {{
                            formatReportingDuration(
                                aggregatedTableTimeEntries.seconds,
                                organization?.interval_format,
                                organization?.number_format
                            )
                        }}
                    </div>
                    <div
                        v-if="showBillableRate"
                        class="justify-end pr-6 flex flex-col items-end gap-1 font-medium">
                        <template v-if="weekBillableTotalLines.length">
                            <span v-for="(line, i) in weekBillableTotalLines" :key="i">{{ line }}</span>
                        </template>
                        <template v-else-if="aggregatedTableTimeEntries.cost">
                            {{
                                formatCents(
                                    aggregatedTableTimeEntries.cost,
                                    getOrganizationCurrencyString(),
                                    organization?.currency_format,
                                    organization?.currency_symbol,
                                    organization?.number_format
                                )
                            }}
                        </template>
                        <template v-else>--</template>
                    </div>
                </div>
            </template>

            <div
                v-else
                class="chart flex flex-col items-center justify-center py-12"
                :class="showBillableRate ? 'col-span-3' : 'col-span-2'">
                <p class="text-lg text-text-primary font-medium">No time entries found</p>
                <p>
                    {{
                        weekOffset === 0
                            ? 'Try to track some time entries this week'
                            : 'No time was tracked in this week.'
                    }}
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped></style>
