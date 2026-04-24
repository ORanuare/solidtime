<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query';
import { computed } from 'vue';
import DashboardCard from '@/Components/Dashboard/DashboardCard.vue';
import RecentNotesCardEntry from '@/Components/Dashboard/RecentNotesCardEntry.vue';
import { ClipboardDocumentListIcon } from '@heroicons/vue/20/solid';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { api } from '@/packages/api/src';
import { LoadingSpinner } from '@/packages/ui/src';

const organizationId = computed(() => getCurrentOrganizationId());
const DASHBOARD_NOTE_ROWS = 5;

const { data: notesResponse, isLoading } = useQuery({
    queryKey: ['dashboardRecentNotes', organizationId],
    queryFn: () =>
        api.getNotes({
            params: { organization: organizationId.value! },
            queries: { page: 1 },
        }),
    enabled: computed(() => !!organizationId.value),
    staleTime: 30_000,
});

const recentNotes = computed(() => {
    if (!notesResponse.value?.data) {
        return [];
    }
    return notesResponse.value.data.slice(0, DASHBOARD_NOTE_ROWS);
});
</script>

<template>
    <DashboardCard title="Recent notes" :icon="ClipboardDocumentListIcon">
        <div v-if="isLoading" class="flex justify-center items-center h-40">
            <LoadingSpinner />
        </div>
        <div v-else-if="recentNotes.length > 0">
            <RecentNotesCardEntry v-for="note in recentNotes" :key="note.id" :note="note" />
        </div>
        <div v-else class="text-center flex flex-1 justify-center items-center py-5 px-3">
            <p class="text-sm text-text-secondary">No notes yet</p>
        </div>
    </DashboardCard>
</template>
