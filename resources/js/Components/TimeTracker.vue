<script setup lang="ts">
import { ClockIcon } from '@heroicons/vue/20/solid';
import CardTitle from '@/packages/ui/src/CardTitle.vue';
import { usePage } from '@inertiajs/vue3';
import { type User } from '@/types/models';
import { computed, onMounted, ref, watch } from 'vue';
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc';
import duration from 'dayjs/plugin/duration';

import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import { storeToRefs } from 'pinia';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { switchOrganization } from '@/utils/useOrganization';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useTagsQuery } from '@/utils/useTagsQuery';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { useTagsStore } from '@/utils/useTags';
import { useProjectsStore } from '@/utils/useProjects';
import TimeTrackerControls from '@/packages/ui/src/TimeTracker/TimeTrackerControls.vue';
import type {
    CreateClientBody,
    CreateProjectBody,
    CreateTimeEntryBody,
    Project,
    Tag,
} from '@/packages/api/src';
import TimeTrackerRunningInDifferentOrganizationOverlay from '@/packages/ui/src/TimeTracker/TimeTrackerRunningInDifferentOrganizationOverlay.vue';
import TimeTrackerMoreOptionsDropdown from '@/packages/ui/src/TimeTracker/TimeTrackerMoreOptionsDropdown.vue';
import TimeEntryCreateModal from '@/packages/ui/src/TimeEntry/TimeEntryCreateModal.vue';
import { useClientsStore } from '@/utils/useClients';
import { getOrganizationCurrencyString } from '@/utils/money';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { canCreateNotes, canCreateProjects, canViewNotes } from '@/utils/permissions';
import TimerNotesModal from '@/Components/Common/Note/TimerNotesModal.vue';
import { useTimeEntriesMutations } from '@/utils/useTimeEntriesMutations';
import { useTimeEntriesInfiniteQuery } from '@/utils/useTimeEntriesInfiniteQuery';
import { useTimerFocus } from '@/utils/useTimerFocus';

const page = usePage<{
    auth: {
        user: User;
    };
}>();

withDefaults(
    defineProps<{
        variant?: 'default' | 'focus';
    }>(),
    { variant: 'default' }
);

dayjs.extend(duration);

dayjs.extend(utc);

const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);

const currentTimeEntryStore = useCurrentTimeEntryStore();
const { currentTimeEntry, isActive, now } = storeToRefs(currentTimeEntryStore);
const { startLiveTimer, stopLiveTimer, setActiveState } = currentTimeEntryStore;

const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();
const { clients } = useClientsQuery();

const emit = defineEmits<{
    change: [];
}>();

const showManualTimeEntryModal = ref(false);
const showTimerNotesModal = ref(false);

const canAccessTimerNotes = computed(() => canViewNotes() || canCreateNotes());

function openTimerNotes() {
    showTimerNotesModal.value = true;
}

const { createTimeEntry: createTimeEntryMutation } = useTimeEntriesMutations();
const { data: timeEntriesData } = useTimeEntriesInfiniteQuery();
const timeEntries = computed(() => timeEntriesData.value?.pages.flatMap((page) => page.data) || []);

watch(isActive, () => {
    if (isActive.value) {
        startLiveTimer();
    } else {
        stopLiveTimer();
    }
    emit('change');
});

onMounted(async () => {
    if (page.props.auth.user.current_team_id) {
        await currentTimeEntryStore.fetchCurrentTimeEntry();
        now.value = dayjs().utc();
    }
});

function updateTimeEntry() {
    if (currentTimeEntry.value.id) {
        useCurrentTimeEntryStore().updateTimer();
    }
}

const timerFocus = useTimerFocus();

function openTimerFocus(anchor?: HTMLElement) {
    timerFocus.open(anchor ?? null);
}

const isRunningInDifferentOrganization = computed(() => {
    return (
        currentTimeEntry.value.organization_id &&
        getCurrentOrganizationId() &&
        currentTimeEntry.value.organization_id !== getCurrentOrganizationId()
    );
});

async function createProject(project: CreateProjectBody): Promise<Project | undefined> {
    const newProject = await useProjectsStore().createProject(project);
    if (newProject) {
        currentTimeEntry.value.project_id = newProject.id;
    }
    return newProject;
}
async function createClient(client: CreateClientBody) {
    return await useClientsStore().createClient(client);
}

function switchToTimeEntryOrganization() {
    if (currentTimeEntry.value.organization_id) {
        switchOrganization(currentTimeEntry.value.organization_id);
    }
}
async function createTag(tag: string): Promise<Tag | undefined> {
    return await useTagsStore().createTag(tag);
}

async function createTimeEntry(timeEntry: Omit<CreateTimeEntryBody, 'member_id'>) {
    await createTimeEntryMutation(timeEntry);
    showManualTimeEntryModal.value = false;
    emit('change');
}

async function createTimeEntryFromCurrentEntry() {
    const { start, end, description, project_id, task_id, billable, tags } = currentTimeEntry.value;
    await createTimeEntry({ start, end, description, project_id, task_id, billable, tags });
    currentTimeEntryStore.$reset();
}

const { tags } = useTagsQuery();

const noteContextProjectName = computed(() => {
    const id = currentTimeEntry.value.project_id;
    if (!id) {
        return undefined;
    }

    return projects.value.find((p) => p.id === id)?.name;
});

const noteContextTaskName = computed(() => {
    const id = currentTimeEntry.value.task_id;
    if (!id) {
        return undefined;
    }

    return tasks.value.find((t) => t.id === id)?.name;
});
</script>

<template>
    <TimerNotesModal
        v-model:show="showTimerNotesModal"
        :project-id="currentTimeEntry.project_id || undefined"
        :task-id="currentTimeEntry.task_id || undefined"
        :project-name="noteContextProjectName"
        :task-name="noteContextTaskName" />
    <TimeEntryCreateModal
        v-model:show="showManualTimeEntryModal"
        :enable-estimated-time="isAllowedToPerformPremiumAction()"
        :create-project="createProject"
        :create-client="createClient"
        :create-tag="createTag"
        :create-time-entry="createTimeEntry"
        :currency="getOrganizationCurrencyString()"
        :can-create-project="canCreateProjects()"
        :organization-billable-rate="organization?.billable_rate ?? null"
        :projects
        :tasks
        :tags
        :clients></TimeEntryCreateModal>
    <CardTitle v-if="variant === 'default'" title="Time Tracker" :icon="ClockIcon"></CardTitle>
    <div
        :class="[
            'relative',
            variant === 'default' ? 'pt-1.5' : 'w-full max-w-3xl mx-auto pt-0',
        ]">
        <TimeTrackerRunningInDifferentOrganizationOverlay
            v-if="isRunningInDifferentOrganization"
            @switch-organization="
                switchToTimeEntryOrganization
            "></TimeTrackerRunningInDifferentOrganizationOverlay>

        <div
            :class="[
                'flex w-full gap-2',
                variant === 'focus' ? 'flex-col sm:flex-row sm:items-start' : 'items-center',
            ]">
            <div class="flex w-full min-w-0 items-center gap-2 flex-1">
                <div class="flex-1 min-w-0">
                    <TimeTrackerControls
                        v-model:current-time-entry="currentTimeEntry"
                        v-model:live-timer="now"
                        :layout="variant === 'focus' ? 'focus' : 'default'"
                        :open-timer-focus="variant === 'default' ? openTimerFocus : undefined"
                        :create-project
                        :enable-estimated-time="isAllowedToPerformPremiumAction()"
                        :can-create-project="canCreateProjects()"
                        :can-add-note="canAccessTimerNotes && variant !== 'focus'"
                        :organization-billable-rate="organization?.billable_rate ?? null"
                        :create-client
                        :clients
                        :tags
                        :tasks
                        :projects
                        :time-entries
                        :create-tag
                        :is-active
                        :currency="getOrganizationCurrencyString()"
                        @start-live-timer="startLiveTimer"
                        @stop-live-timer="stopLiveTimer"
                        @start-timer="setActiveState(true)"
                        @stop-timer="setActiveState(false)"
                        @update-time-entry="updateTimeEntry"
                        @create-time-entry="createTimeEntryFromCurrentEntry"
                        @add-note="openTimerNotes"></TimeTrackerControls>
                </div>
                <div v-if="variant === 'default'" class="shrink-0">
                    <TimeTrackerMoreOptionsDropdown
                        @manual-entry="showManualTimeEntryModal = true"></TimeTrackerMoreOptionsDropdown>
                </div>
            </div>
        </div>
    </div>
</template>
