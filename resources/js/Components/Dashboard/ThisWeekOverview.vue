<script setup lang="ts">
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { BarChart } from 'echarts/charts';
import {
    GridComponent,
    LegendComponent,
    TitleComponent,
    TooltipComponent,
} from 'echarts/components';
import VChart, { THEME_KEY } from 'vue-echarts';
import { computed, provide, inject, type ComputedRef } from 'vue';
import StatCard from '@/Components/Common/StatCard.vue';
import { ClockIcon } from '@heroicons/vue/20/solid';
import CardTitle from '@/packages/ui/src/CardTitle.vue';
import LinearGradient from 'zrender/lib/graphic/LinearGradient';
import ProjectsChartCard from '@/Components/Dashboard/ProjectsChartCard.vue';
import ThisWeekReportingTable from '@/Components/Dashboard/ThisWeekReportingTable.vue';
import { Button } from '@/packages/ui/src/Buttons';
import { formatReportingDuration, getDayJsInstance, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import { formatCents } from '@/packages/ui/src/utils/money';
import { getWeekStart } from '@/packages/ui/src/utils/settings';
import { useCssVariable } from '@/packages/ui/src';
import { getOrganizationCurrencySymbol } from '@/packages/ui/src/utils/money';
import { useDashboardWeekOffset } from '@/utils/useDashboardWeekOffset';
import { useQuery } from '@tanstack/vue-query';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { api, type Organization } from '@/packages/api/src';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

use([CanvasRenderer, BarChart, TitleComponent, GridComponent, TooltipComponent, LegendComponent]);

provide(THEME_KEY, 'dark');

const weekdays = computed(() => {
    const daysOrder = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const dayMapping: Record<string, string> = {
        monday: 'Mon',
        tuesday: 'Tue',
        wednesday: 'Wed',
        thursday: 'Thu',
        friday: 'Fri',
        saturday: 'Sat',
        sunday: 'Sun',
    };
    if (dayMapping[getWeekStart()]) {
        const customOrder = [];
        const startIndex = daysOrder.indexOf(dayMapping[getWeekStart()]!);

        for (let i = startIndex; i < 7 + startIndex; i++) {
            customOrder.push(daysOrder[i % daysOrder.length]!);
        }

        return customOrder;
    } else {
        return daysOrder;
    }
});

const accentColor = useCssVariable('--theme-color-chart');

// Get the organization ID using the utility function
const organizationId = computed(() => getCurrentOrganizationId());

const organization = inject<ComputedRef<Organization>>('organization');

const { weekOffset, weekOffsetQueries, shiftWeekBy, goToThisWeek } = useDashboardWeekOffset();

const weekSectionTitle = computed(() => {
    if (weekOffset.value === 0) {
        return 'This week';
    }
    const d = getDayJsInstance()();
    const start = getLocalizedDayJs(d.format('YYYY-MM-DD'))
        .startOf('week')
        .add(weekOffset.value, 'week');
    const end = start.endOf('week');
    return `${start.format('MMM D')} - ${end.format('MMM D')}`;
});

// Set up the queries
const { data: weeklyProjectOverview, isFetching: isFetchingProjectOverview } = useQuery({
    queryKey: ['weeklyProjectOverview', organizationId, weekOffset],
    queryFn: () => {
        return api.weeklyProjectOverview({
            params: {
                organization: organizationId.value!,
            },
            queries: weekOffsetQueries.value,
        });
    },
    enabled: computed(() => !!organizationId.value),
    staleTime: 1000 * 30, // 30 seconds
    placeholderData: (previousData) => previousData,
});

const { data: totalWeeklyTime, isFetching: isFetchingTotalTime } = useQuery({
    queryKey: ['totalWeeklyTime', organizationId, weekOffset],
    queryFn: () => {
        return api.totalWeeklyTime({
            params: {
                organization: organizationId.value!,
            },
            queries: weekOffsetQueries.value,
        });
    },
    enabled: computed(() => !!organizationId.value),
    staleTime: 1000 * 30, // 30 seconds
    placeholderData: (previousData) => previousData,
});

const { data: totalWeeklyBillableTime, isFetching: isFetchingBillableTime } = useQuery({
    queryKey: ['totalWeeklyBillableTime', organizationId, weekOffset],
    queryFn: () => {
        return api.totalWeeklyBillableTime({
            params: {
                organization: organizationId.value!,
            },
            queries: weekOffsetQueries.value,
        });
    },
    enabled: computed(() => !!organizationId.value),
    staleTime: 1000 * 30, // 30 seconds
    placeholderData: (previousData) => previousData,
});

const { data: totalWeeklyBillableAmount, isFetching: isFetchingBillableAmount } = useQuery({
    queryKey: ['totalWeeklyBillableAmount', organizationId, weekOffset],
    queryFn: () => {
        return api.totalWeeklyBillableAmount({
            params: {
                organization: organizationId.value!,
            },
            queries: weekOffsetQueries.value,
        });
    },
    enabled: computed(() => !!organizationId.value),
    staleTime: 1000 * 30, // 30 seconds
    placeholderData: (previousData) => previousData,
});

/** Non-primary billable totals for this week (no FX; headline stays primary workspace currency). */
const weeklyBillableNonPrimaryLines = computed(() => {
    const payload = totalWeeklyBillableAmount.value;
    if (!payload?.amounts_by_currency?.length) {
        return [];
    }
    const primary = payload.currency;
    return payload.amounts_by_currency
        .filter((row) => row.currency !== primary && row.value !== 0)
        .slice()
        .sort((a, b) => a.currency.localeCompare(b.currency));
});

const { data: weeklyHistory, isFetching: isFetchingWeeklyHistory } = useQuery({
    queryKey: ['weeklyHistory', organizationId, weekOffset],
    queryFn: () => {
        return api.weeklyHistory({
            params: {
                organization: organizationId.value!,
            },
            queries: weekOffsetQueries.value,
        });
    },
    enabled: computed(() => !!organizationId.value),
    staleTime: 1000 * 30, // 30 seconds
    placeholderData: (previousData) => previousData,
});

const isThisWeekSummaryFetching = computed(
    () =>
        isFetchingProjectOverview.value ||
        isFetchingTotalTime.value ||
        isFetchingBillableTime.value ||
        isFetchingBillableAmount.value ||
        isFetchingWeeklyHistory.value
);

const seriesData = computed(() => {
    if (!weeklyHistory.value) {
        return [];
    }
    return weeklyHistory.value?.map((el) => {
        return {
            value: el.duration,
            ...{
                itemStyle: {
                    borderColor: new LinearGradient(0, 0, 0, 1, [
                        {
                            offset: 0,
                            color: 'rgba(' + accentColor.value + ',0.7)',
                        },
                        {
                            offset: 1,
                            color: 'rgba(' + accentColor.value + ',0.5)',
                        },
                    ]),
                    emphasis: {
                        color: new LinearGradient(0, 0, 0, 1, [
                            {
                                offset: 0,
                                color: 'rgba(' + accentColor.value + ',0.9)',
                            },
                            {
                                offset: 1,
                                color: 'rgba(' + accentColor.value + ',0.7)',
                            },
                        ]),
                    },
                    borderRadius: [12, 12, 0, 0],
                    color: new LinearGradient(0, 0, 0, 1, [
                        {
                            offset: 0,
                            color: 'rgba(' + accentColor.value + ',0.7)',
                        },
                        {
                            offset: 1,
                            color: 'rgba(' + accentColor.value + ',0.5)',
                        },
                    ]),
                },
            },
        };
    });
});

const markLineColor = useCssVariable('--color-border-secondary');
const labelColor = useCssVariable('--color-text-secondary');
const option = computed(() => {
    return {
        tooltip: {
            trigger: 'item',
        },
        grid: {
            top: 0,
            right: 0,
            bottom: 50,
            left: 0,
        },
        backgroundColor: 'transparent',
        xAxis: {
            type: 'category',
            data: weekdays.value,
            axisLine: {
                show: false,
            },
            axisLabel: {
                fontSize: 14,
                fontWeight: 500,
                margin: 24,
                fontFamily: 'Inter, sans-serif',
                color: labelColor.value,
            },
            axisTick: {
                show: false,
            },
        },
        yAxis: {
            type: 'value',
            axisLabel: {
                show: false,
            },
            splitLine: {
                lineStyle: {
                    color: markLineColor.value,
                },
            },
        },
        series: [
            {
                data: seriesData.value,
                type: 'bar',
                tooltip: {
                    valueFormatter: (value: number) => {
                        return formatReportingDuration(
                            value,
                            organization?.value?.interval_format,
                            organization?.value?.number_format
                        );
                    },
                },
            },
        ],
    };
});
</script>

<template>
    <div
        class="grid space-y-5 sm:space-y-0 sm:gap-x-6 xl:gap-x-6 grid-cols-1 lg:grid-cols-3 xl:grid-cols-4">
        <div class="col-span-2 xl:col-span-3">
            <CardTitle class="pb-8" :title="weekSectionTitle" :icon="ClockIcon">
                <template #actions>
                    <div class="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-8 w-8 p-0"
                            data-testid="dashboard-week-prev"
                            aria-label="Previous week"
                            @click="shiftWeekBy(-1)">
                            <ChevronLeft class="h-4 w-4" />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-8 w-8 p-0"
                            data-testid="dashboard-week-next"
                            aria-label="Next week"
                            :disabled="weekOffset >= 0"
                            @click="shiftWeekBy(1)">
                            <ChevronRight class="h-4 w-4" />
                        </Button>
                        <Button
                            v-if="weekOffset < 0"
                            variant="ghost"
                            size="sm"
                            data-testid="dashboard-week-this-week"
                            @click="goToThisWeek()">
                            This week
                        </Button>
                    </div>
                </template>
            </CardTitle>
            <div
                :class="[
                    'transition-opacity duration-200',
                    isThisWeekSummaryFetching ? 'opacity-60' : 'opacity-100',
                ]">
                <v-chart
                    v-if="weeklyHistory !== undefined"
                    :autoresize="true"
                    class="chart"
                    :option="option" />
            </div>

            <div class="mt-6">
                <ThisWeekReportingTable></ThisWeekReportingTable>
            </div>
        </div>
        <div
            :class="[
                'space-y-6 transition-opacity duration-200',
                isThisWeekSummaryFetching ? 'opacity-60' : 'opacity-100',
            ]">
            <StatCard
                title="Spent Time"
                :value="
                    totalWeeklyTime
                        ? formatReportingDuration(
                              totalWeeklyTime,
                              organization?.interval_format,
                              organization?.number_format
                          )
                        : '--'
                " />
            <StatCard
                title="Billable Time"
                :value="
                    totalWeeklyBillableTime
                        ? formatReportingDuration(
                              totalWeeklyBillableTime,
                              organization?.interval_format,
                              organization?.number_format
                          )
                        : '--'
                " />
            <StatCard
                title="Billable Amount"
                :value="
                    totalWeeklyBillableAmount
                        ? formatCents(
                              totalWeeklyBillableAmount.value,
                              totalWeeklyBillableAmount.currency,
                              organization?.currency_format,
                              getOrganizationCurrencySymbol(totalWeeklyBillableAmount.currency),
                              organization?.number_format
                          )
                        : '--'
                ">
                <template v-if="weeklyBillableNonPrimaryLines.length > 0" #extra>
                    <ul class="space-y-1 text-xl leading-snug font-medium text-text-primary">
                        <li
                            v-for="row in weeklyBillableNonPrimaryLines"
                            :key="row.currency"
                            class="tabular-nums">
                            {{
                                formatCents(
                                    row.value,
                                    row.currency,
                                    organization?.currency_format,
                                    getOrganizationCurrencySymbol(row.currency),
                                    organization?.number_format
                                )
                            }}
                        </li>
                    </ul>
                </template>
            </StatCard>
            <ProjectsChartCard
                v-if="weeklyProjectOverview"
                :weekly-project-overview="weeklyProjectOverview"></ProjectsChartCard>
        </div>
    </div>
</template>

<style scoped>
.chart {
    height: 280px;
    background: transparent;
}
</style>
