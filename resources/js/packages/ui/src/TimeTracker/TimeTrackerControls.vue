<script setup lang="ts">
import TimeTrackerTagDropdown from '@/packages/ui/src/TimeTracker/TimeTrackerTagDropdown.vue';
import TimeTrackerStartStop from '@/packages/ui/src/TimeTrackerStartStop.vue';
import TimeTrackerRangeSelector from '@/packages/ui/src/TimeTracker/TimeTrackerRangeSelector.vue';
import BillableToggleButton from '@/packages/ui/src/Input/BillableToggleButton.vue';
import TimeTrackerProjectTaskDropdown from '@/packages/ui/src/TimeTracker/TimeTrackerProjectTaskDropdown.vue';
import type {
    CreateClientBody,
    CreateProjectBody,
    Project,
    Tag,
    Task,
    TimeEntry,
    Client,
} from '@/packages/api/src';
import { computed, nextTick, ref, watch } from 'vue';
import type { Dayjs } from 'dayjs';
import { useFocus } from '@vueuse/core';
import { autoUpdate, flip, limitShift, offset, shift, useFloating } from '@floating-ui/vue';
import TimeTrackerRecentlyTrackedEntry from '@/packages/ui/src/TimeTracker/TimeTrackerRecentlyTrackedEntry.vue';
import { useSelectEvents } from '@/packages/ui/src/utils/select';
import { ArrowsPointingOutIcon, ClipboardDocumentListIcon } from '@heroicons/vue/20/solid';
import { twMerge } from 'tailwind-merge';
import { Button } from '@/packages/ui/src/Buttons';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/packages/ui/src/tooltip';
import ProjectBadge from '@/packages/ui/src/Project/ProjectBadge.vue';
import {
    dedupeRecentTimeEntries,
    dedupeTimeEntriesByContext,
    timeEntryMatchesSearchText,
} from '@/utils/recentTimeEntries';

const currentTimeEntry = defineModel<TimeEntry>('currentTimeEntry', {
    required: true,
});
const liveTimer = defineModel<Dayjs | null>('liveTimer', { required: true });

const currentTimeEntryDescriptionInput = ref<HTMLInputElement | null>(null);

const props = withDefaults(
    defineProps<{
        projects: Project[];
        tasks: Task[];
        tags: Tag[];
        clients: Client[];
        timeEntries: TimeEntry[];
        createTag: (name: string) => Promise<Tag | undefined>;
        createProject: (project: CreateProjectBody) => Promise<Project | undefined>;
        createClient: (client: CreateClientBody) => Promise<Client | undefined>;
        isActive: boolean;
        currency: string;
        organizationBillableRate: number | null;
        enableEstimatedTime: boolean;
        canCreateProject: boolean;
        /**
         * Show notes beside tag / billable; parent opens notes list (with create) or workspace note form.
         */
        canAddNote?: boolean;
        /** Stacked layout with prominent duration (full-page timer focus). */
        layout?: 'default' | 'focus';
        /** When set, shows focus shortcut beside start/stop (omit on timer focus page). */
        openTimerFocus?: (anchor?: HTMLElement) => void;
    }>(),
    { canAddNote: false, layout: 'default' }
);

const emit = defineEmits<{
    startTimer: [];
    stopTimer: [];
    updateTimeEntry: [];
    startLiveTimer: [];
    stopLiveTimer: [];
    createTimeEntry: [];
    addNote: [];
}>();

function updateProject() {
    setBillableDefaultForProject();
    emit('updateTimeEntry');
}

function setAndStartTimer(timeEntry: TimeEntry) {
    setCurrentTimeEntry(timeEntry);
    if (!props.isActive) {
        emit('startTimer');
    } else {
        emit('updateTimeEntry');
    }
}

function setCurrentTimeEntry(timeEntry: TimeEntry) {
    currentTimeEntry.value.description = timeEntry.description;
    currentTimeEntry.value.project_id = timeEntry.project_id;
    currentTimeEntry.value.task_id = timeEntry.task_id;
    currentTimeEntry.value.tags = timeEntry.tags;
    currentTimeEntry.value.billable = timeEntry.billable;
}

/** Quick-pick: fill context only (does not start the timer). */
function applyRecentTimeEntryContext(timeEntry: TimeEntry) {
    setCurrentTimeEntry(timeEntry);
    if (props.isActive) {
        emit('updateTimeEntry');
    }
}

function startTimerIfNotActive() {
    if (highlightedDropdownEntryId.value) {
        const timeEntry = filteredRecentlyTrackedTimeEntries.value.find(
            (item) => item.id === highlightedDropdownEntryId.value
        );
        if (timeEntry) {
            setCurrentTimeEntry(timeEntry);
            showDropdown.value = false;
        }
    } else {
        currentTimeEntry.value.description = tempDescription.value;
    }

    if (!props.isActive) {
        emit('startTimer');
    } else {
        emit('updateTimeEntry');
    }
}

function setBillableDefaultForProject() {
    const project = props.projects.find(
        (project) => project.id === currentTimeEntry.value.project_id
    );
    if (project) {
        currentTimeEntry.value.billable = project.is_billable;
    }
}

const blockRefocus = ref(false);

function onToggleButtonPress(newState: boolean) {
    if (newState) {
        emit('startTimer');
        if (!blockRefocus.value) {
            currentTimeEntryDescriptionInput.value?.focus();
        }
    } else {
        emit('stopTimer');
    }
}

const tempDescription = ref(currentTimeEntry.value.description);
watch(
    () => currentTimeEntry.value.description,
    () => {
        tempDescription.value = currentTimeEntry.value.description;
    }
);

function updateTimeEntryDescription() {
    if (currentTimeEntry.value.description !== tempDescription.value) {
        currentTimeEntry.value.description = tempDescription.value;
        emit('updateTimeEntry');
    }
}

const searchContext = computed(() => ({
    projects: props.projects,
    tasks: props.tasks,
    clients: props.clients,
}));

const dedupedFinishedTimeEntries = computed(() =>
    dedupeTimeEntriesByContext(props.timeEntries, true)
);

const filteredRecentlyTrackedTimeEntries = computed(() => {
    const q = tempDescription.value?.trim() ?? '';
    return dedupedFinishedTimeEntries.value
        .filter((item) => timeEntryMatchesSearchText(item, q, searchContext.value))
        .slice(0, 5);
});

const recentQuickPickEntries = computed(() => {
    if (props.layout !== 'focus') {
        return [] as TimeEntry[];
    }
    return dedupeRecentTimeEntries(props.timeEntries, {
        maxItems: 4,
        onlyFinished: true,
        quickPick: true,
    });
});

function recentChipLabel(entry: TimeEntry): string {
    const project = props.projects.find((p) => p.id === entry.project_id);
    const task = props.tasks.find((t) => t.id === entry.task_id);
    if (project && task) {
        return `${project.name} › ${task.name}`;
    }
    if (project) {
        return project.name;
    }
    if (task) {
        return task.name;
    }
    const d = entry.description?.trim();
    if (d) {
        return d.length > 48 ? `${d.slice(0, 45)}…` : d;
    }
    return 'Recent';
}

const showDropdown = ref(false);
const { focused } = useFocus(currentTimeEntryDescriptionInput);

watch(focused, (focused) => {
    nextTick(() => {
        // make sure the click event on the dropdown does not get interrupted
        showDropdown.value = focused;

        // make sure that the input does not get refocused after the dropdown is closed
        if (!focused) {
            blockRefocus.value = true;
            setTimeout(() => {
                blockRefocus.value = false;
            }, 100);
        }
    });
});

const floating = ref(null);
const { floatingStyles } = useFloating(currentTimeEntryDescriptionInput, floating, {
    placement: 'bottom-start',
    strategy: () => (props.layout === 'focus' ? 'fixed' : 'absolute'),
    whileElementsMounted: autoUpdate,
    middleware: [
        offset(10),
        shift({
            limiter: limitShift({
                offset: 5,
            }),
        }),
        flip({
            fallbackAxisSideDirection: 'start',
        }),
    ],
});
const highlightedDropdownEntryId = ref<string | null>(null);

const noteActionIconClass = computed(() => {
    const t = currentTimeEntry.value;
    if (t.project_id || t.task_id) {
        return 'text-input-select-active focus:text-input-select-active-hover hover:text-input-select-active-hover';
    }

    return 'text-icon-default focus:text-icon-active hover:text-icon-active';
});

useSelectEvents(
    filteredRecentlyTrackedTimeEntries,
    highlightedDropdownEntryId,
    (item) => item.id,
    showDropdown
);

function onOpenTimerFocusClick(e: MouseEvent) {
    const el = e.currentTarget;
    props.openTimerFocus?.(el instanceof HTMLElement ? el : undefined);
}
</script>

<template>
    <div
        v-if="layout === 'focus'"
        class="flex flex-col w-full relative gap-8 @container"
        data-testid="dashboard_timer">
        <div class="flex flex-col items-center justify-center gap-5 px-1">
            <TimeTrackerRangeSelector
                v-model:current-time-entry="currentTimeEntry"
                v-model:live-timer="liveTimer"
                timer-variant="focus"
                @start-live-timer="emit('startLiveTimer')"
                @stop-live-timer="emit('stopLiveTimer')"
                @update-timer="emit('updateTimeEntry')"
                @start-timer="emit('startTimer')"
                @create-time-entry="emit('createTimeEntry')"
                @keydown.enter="startTimerIfNotActive"></TimeTrackerRangeSelector>
            <TimeTrackerStartStop
                :active="isActive"
                size="large"
                @changed="onToggleButtonPress"></TimeTrackerStartStop>
        </div>
        <div
            class="flex flex-col w-full rounded-lg bg-card-background border-card-border border transition shadow-card overflow-visible">
            <div class="flex flex-1 flex-col relative min-w-0 overflow-visible">
                <input
                    ref="currentTimeEntryDescriptionInput"
                    v-model="tempDescription"
                    placeholder="What are you working on?"
                    data-testid="time_entry_description"
                    class="w-full py-4 sm:py-5 px-4 text-lg sm:text-xl text-text-primary bg-transparent border-none border-b border-b-card-background-separator placeholder-text-secondary focus:ring-0 transition"
                    type="text"
                    @keydown.enter="startTimerIfNotActive"
                    @keydown.esc="showDropdown = false"
                    @blur="updateTimeEntryDescription" />
                <div
                    v-if="showDropdown && filteredRecentlyTrackedTimeEntries.length > 0"
                    ref="floating"
                    class="z-[105] w-[min(640px,100vw-2rem)] max-h-[min(320px,50vh)] overflow-y-auto"
                    :style="floatingStyles">
                    <div
                        class="rounded-lg w-full border border-card-border overflow-hidden shadow-dropdown bg-card-background">
                        <div
                            class="text-text-tertiary text-xs font-semibold border-b border-border-tertiary px-2 py-1.5">
                            Recently Tracked Time Entries
                        </div>
                        <div class="text-text-secondary py-1 px-1.5">
                            <TimeTrackerRecentlyTrackedEntry
                                v-for="timeEntry in filteredRecentlyTrackedTimeEntries"
                                :key="timeEntry.id"
                                :time-entry="timeEntry"
                                :highlighted="highlightedDropdownEntryId === timeEntry.id"
                                :projects="projects"
                                :tasks="tasks"
                                @mousedown="setAndStartTimer(timeEntry)"
                                @mouseenter="
                                    highlightedDropdownEntryId = timeEntry.id
                                "></TimeTrackerRecentlyTrackedEntry>
                        </div>
                    </div>
                </div>
            </div>
            <div
                class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4 border-t border-card-background-separator">
                <div class="flex items-center min-w-0 w-full sm:flex-1 sm:w-auto">
                    <TimeTrackerProjectTaskDropdown
                        v-model:project="currentTimeEntry.project_id"
                        v-model:task="currentTimeEntry.task_id"
                        variant="outline"
                        :create-client
                        :can-create-project
                        :clients
                        :create-project
                        :currency="currency"
                        :organization-billable-rate="organizationBillableRate"
                        :projects="projects"
                        :tasks="tasks"
                        :enable-estimated-time="enableEstimatedTime"
                        @changed="updateProject"></TimeTrackerProjectTaskDropdown>
                </div>
                <div
                    class="flex flex-wrap items-center gap-1 sm:gap-2 justify-end sm:justify-start shrink-0">
                    <TimeTrackerTagDropdown
                        v-model="currentTimeEntry.tags"
                        :create-tag
                        :tags="tags"
                        @changed="$emit('updateTimeEntry')"></TimeTrackerTagDropdown>
                    <BillableToggleButton
                        v-model="currentTimeEntry.billable"
                        @changed="$emit('updateTimeEntry')"></BillableToggleButton>
                    <TooltipProvider v-if="canAddNote">
                        <Tooltip disable-closing-trigger>
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    data-testid="time_tracker_add_note"
                                    aria-label="View and add notes for the selected project and task"
                                    :class="
                                        twMerge(
                                            noteActionIconClass,
                                            'flex-shrink-0 ring-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring transition focus:bg-card-background-separator hover:bg-card-background-separator rounded-full w-10 h-10 flex items-center justify-center'
                                        )
                                    "
                                    @click="$emit('addNote')">
                                    <ClipboardDocumentListIcon
                                        class="w-5 h-5 lg:h-6 lg:w-6"></ClipboardDocumentListIcon>
                                </button>
                            </TooltipTrigger>
                            <TooltipContent> Notes </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>
            </div>
            <div
                v-if="recentQuickPickEntries.length > 0"
                class="flex w-full flex-wrap gap-1.5 border-t border-card-background-separator px-3 py-2">
                <TooltipProvider
                    v-for="entry in recentQuickPickEntries"
                    :key="`chip-focus-${entry.id}`">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="min-w-0 max-w-full rounded-md text-left ring-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                @click="applyRecentTimeEntryContext(entry)">
                                <ProjectBadge
                                    class="min-w-0 max-w-[min(12rem,100%)]"
                                    size="base"
                                    :color="projects.find((p) => p.id === entry.project_id)?.color">
                                    <span class="block truncate text-xs font-medium text-text-primary">
                                        {{ recentChipLabel(entry) }}
                                    </span>
                                </ProjectBadge>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent>
                            <p class="max-w-sm">{{ recentChipLabel(entry) }}</p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
        </div>
    </div>
    <div v-else class="flex items-center relative @container" data-testid="dashboard_timer">
        <div
            class="flex w-full min-w-0 flex-col rounded-lg border border-card-border bg-card-background shadow-card transition">
            <div class="flex w-full min-w-0 flex-col @2xl:flex-row @2xl:justify-between">
                <div class="flex min-w-0 flex-1 items-center relative">
                    <input
                        ref="currentTimeEntryDescriptionInput"
                        v-model="tempDescription"
                        placeholder="What are you working on?"
                        data-testid="time_entry_description"
                        class="w-full border-none border-b border-b-card-background-separator bg-transparent py-4 px-3.5 text-base text-text-primary placeholder-text-secondary transition focus:outline-none focus:ring-0 sm:py-2.5 @2xl:rounded-l-lg @2xl:border-b-0 @2xl:px-4 @2xl:py-2.5"
                        type="text"
                        @keydown.enter="startTimerIfNotActive"
                        @keydown.esc="showDropdown = false"
                        @blur="updateTimeEntryDescription" />
                    <div class="flex shrink-0 items-center gap-1 pr-3 @2xl:hidden">
                        <TooltipProvider v-if="openTimerFocus">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-9 w-9 shrink-0"
                                        data-testid="timer_focus_enter"
                                        aria-label="Open timer focus mode"
                                        @click="onOpenTimerFocusClick">
                                        <ArrowsPointingOutIcon class="h-4 w-4 text-icon-default" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>Focus mode</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        <TimeTrackerStartStop
                            :active="isActive"
                            @changed="onToggleButtonPress"></TimeTrackerStartStop>
                    </div>
                    <div
                        v-if="showDropdown && filteredRecentlyTrackedTimeEntries.length > 0"
                        ref="floating"
                        class="z-[105] w-[min(640px,100vw-2rem)]"
                        :style="floatingStyles">
                        <div
                            class="rounded-lg w-full border border-card-border overflow-hidden shadow-dropdown bg-card-background">
                            <div
                                class="text-text-tertiary text-xs font-semibold border-b border-border-tertiary px-2 py-1.5">
                                Recently Tracked Time Entries
                            </div>
                            <div class="text-text-secondary py-1 px-1.5">
                                <TimeTrackerRecentlyTrackedEntry
                                    v-for="timeEntry in filteredRecentlyTrackedTimeEntries"
                                    :key="timeEntry.id"
                                    :time-entry="timeEntry"
                                    :highlighted="highlightedDropdownEntryId === timeEntry.id"
                                    :projects="projects"
                                    :tasks="tasks"
                                    @mousedown="setAndStartTimer(timeEntry)"
                                    @mouseenter="
                                        highlightedDropdownEntryId = timeEntry.id
                                    "></TimeTrackerRecentlyTrackedEntry>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex min-w-0 shrink items-center justify-between pl-2">
                <div class="flex items-center w-[130px] @2xl:w-auto shrink min-w-0">
                    <TimeTrackerProjectTaskDropdown
                        v-model:project="currentTimeEntry.project_id"
                        v-model:task="currentTimeEntry.task_id"
                        variant="outline"
                        :create-client
                        :can-create-project
                        :clients
                        :create-project
                        :currency="currency"
                        :organization-billable-rate="organizationBillableRate"
                        :projects="projects"
                        :tasks="tasks"
                        :enable-estimated-time="enableEstimatedTime"
                        @changed="updateProject"></TimeTrackerProjectTaskDropdown>
                </div>
                <div class="flex items-center space-x-0 @4xl:space-x-2 px-2 @4xl:px-4 shrink-0">
                    <TimeTrackerTagDropdown
                        v-model="currentTimeEntry.tags"
                        :create-tag
                        :tags="tags"
                        @changed="$emit('updateTimeEntry')"></TimeTrackerTagDropdown>
                    <BillableToggleButton
                        v-model="currentTimeEntry.billable"
                        @changed="$emit('updateTimeEntry')"></BillableToggleButton>
                    <TooltipProvider v-if="canAddNote">
                        <Tooltip disable-closing-trigger>
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    data-testid="time_tracker_add_note"
                                    aria-label="View and add notes for the selected project and task"
                                    :class="
                                        twMerge(
                                            noteActionIconClass,
                                            'flex-shrink-0 ring-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring transition focus:bg-card-background-separator hover:bg-card-background-separator rounded-full w-10 h-10 flex items-center justify-center'
                                        )
                                    "
                                    @click="$emit('addNote')">
                                    <ClipboardDocumentListIcon
                                        class="w-5 h-5 lg:h-6 lg:w-6"></ClipboardDocumentListIcon>
                                </button>
                            </TooltipTrigger>
                            <TooltipContent> Notes </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>
                <div class="border-l border-card-border">
                    <TimeTrackerRangeSelector
                        v-model:current-time-entry="currentTimeEntry"
                        v-model:live-timer="liveTimer"
                        @start-live-timer="emit('startLiveTimer')"
                        @stop-live-timer="emit('stopLiveTimer')"
                        @update-timer="emit('updateTimeEntry')"
                        @start-timer="emit('startTimer')"
                        @create-time-entry="emit('createTimeEntry')"
                        @keydown.enter="startTimerIfNotActive"></TimeTrackerRangeSelector>
                </div>
                </div>
            </div>
        </div>
        <div class="pl-2 @2xl:pl-4 pr-3 hidden @2xl:flex @2xl:items-center @2xl:gap-1">
            <TooltipProvider v-if="openTimerFocus">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-11 w-11 shrink-0"
                            data-testid="timer_focus_enter"
                            aria-label="Open timer focus mode"
                            @click="onOpenTimerFocusClick">
                            <ArrowsPointingOutIcon class="h-5 w-5 text-icon-default" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Focus mode</TooltipContent>
                </Tooltip>
            </TooltipProvider>
            <TimeTrackerStartStop
                :active="isActive"
                size="large"
                @changed="onToggleButtonPress"></TimeTrackerStartStop>
        </div>
    </div>
</template>

<style scoped></style>
