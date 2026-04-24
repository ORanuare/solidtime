<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import TimeTracker from '@/Components/TimeTracker.vue';
import RecentlyTrackedTasksCard from '@/Components/Dashboard/RecentlyTrackedTasksCard.vue';
import LastSevenDaysCard from '@/Components/Dashboard/LastSevenDaysCard.vue';
import TeamActivityCard from '@/Components/Dashboard/TeamActivityCard.vue';
import RecentNotesCard from '@/Components/Dashboard/RecentNotesCard.vue';
import ThisWeekOverview from '@/Components/Dashboard/ThisWeekOverview.vue';
import ActivityGraphCard from '@/Components/Dashboard/ActivityGraphCard.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import { canViewMembers, canViewNotes } from '@/utils/permissions';
import { useQueryClient } from '@tanstack/vue-query';

const queryClient = useQueryClient();

const refreshDashboardData = () => {
    // Invalidate all dashboard queries to trigger refetching
    queryClient.invalidateQueries({ queryKey: ['latestTasks'] });
    queryClient.invalidateQueries({ queryKey: ['lastSevenDays'] });
    queryClient.invalidateQueries({ queryKey: ['dailyTrackedHours'] });
    queryClient.invalidateQueries({ queryKey: ['latestTeamActivity'] });
    queryClient.invalidateQueries({ queryKey: ['dashboardRecentNotes'] });
    queryClient.invalidateQueries({ queryKey: ['weeklyProjectOverview'] });
    queryClient.invalidateQueries({ queryKey: ['totalWeeklyTime'] });
    queryClient.invalidateQueries({ queryKey: ['totalWeeklyBillableTime'] });
    queryClient.invalidateQueries({ queryKey: ['totalWeeklyBillableAmount'] });
    queryClient.invalidateQueries({ queryKey: ['weeklyHistory'] });
    queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
};
</script>

<template>
    <AppLayout title="Dashboard" data-testid="dashboard_view">
        <MainContainer
            class="pt-5 sm:pt-8 pb-4 sm:pb-6 border-b border-default-background-separator">
            <TimeTracker @change="refreshDashboardData"></TimeTracker>
        </MainContainer>

        <MainContainer
            class="grid gap-2 sm:gap-4 grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 pt-3 sm:pt-5 pb-4 sm:pb-6 border-b border-default-background-separator items-stretch">
            <RecentlyTrackedTasksCard></RecentlyTrackedTasksCard>
            <LastSevenDaysCard></LastSevenDaysCard>
            <ActivityGraphCard></ActivityGraphCard>
            <RecentNotesCard
                v-if="canViewNotes()"
                class="flex lg:hidden xl:flex"></RecentNotesCard>
        </MainContainer>
        <MainContainer class="pt-5 pb-2 sm:pb-3">
            <ThisWeekOverview></ThisWeekOverview>
        </MainContainer>
        <MainContainer
            v-if="canViewMembers()"
            class="pt-0 pb-6 sm:pb-8 border-t border-default-background-separator">
            <TeamActivityCard></TeamActivityCard>
        </MainContainer>
    </AppLayout>
</template>
