<script setup lang="ts">
import TimeTrackerTagDropdown from '@/packages/ui/src/TimeTracker/TimeTrackerTagDropdown.vue';
import TimeTrackerStartStop from '@/packages/ui/src/TimeTrackerStartStop.vue';
import TimeTrackerRangeSelector from '@/packages/ui/src/TimeTracker/TimeTrackerRangeSelector.vue';
import BillableToggleButton from '@/packages/ui/src/Input/BillableToggleButton.vue';
import TimeTrackerProjectTaskDropdown from '@/packages/ui/src/TimeTracker/TimeTrackerProjectTaskDropdown.vue';
import type {
    CreateClientBody,
    CreateProjectBody,
    Client,
    Project,
    Tag,
    Task,
    TimeEntry,
} from '@/packages/api/src';
import { computed, nextTick, ref, watch } from 'vue';
import type { Dayjs } from 'dayjs';
import { useFocus, useResizeObserver } from '@vueuse/core';
import { autoUpdate, flip, limitShift, offset, shift, useFloating } from '@floating-ui/vue';
import TimeTrackerRecentlyTrackedEntry from '@/packages/ui/src/TimeTracker/TimeTrackerRecentlyTrackedEntry.vue';
import { useSelectEvents } from '@/packages/ui/src/utils/select';
import {
    ArrowsPointingOutIcon,
    ChevronLeftIcon,
    ChevronRightIcon as ChevronRightIconSolid,
    ClipboardDocumentListIcon,
    FolderIcon,
    PlusIcon,
    QueueListIcon,
} from '@heroicons/vue/20/solid';
import { ChevronRightIcon } from '@heroicons/vue/16/solid';
import { twMerge } from 'tailwind-merge';
import { Button } from '@/packages/ui/src/Buttons';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/packages/ui/src/tooltip';
import ProjectBadge from '@/packages/ui/src/Project/ProjectBadge.vue';
import ProjectCreateModal from '@/packages/ui/src/Project/ProjectCreateModal.vue';
import TaskCreateModal from '@/Components/Common/Task/TaskCreateModal.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/packages/ui/src/dropdown-menu';
import {
    dedupeTimeEntriesByContext,
    dedupeTimeEntriesByProjectTask,
    timeEntryContextKeyForFocusPicker,
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
        canCreateTask?: boolean;
        /**
         * Show notes beside tag / billable; parent opens notes list (with create) or workspace note form.
         */
        canAddNote?: boolean;
        /** Stacked layout with prominent duration (full-page timer focus). */
        layout?: 'default' | 'focus';
        /** When set, shows focus shortcut beside start/stop (omit on timer focus page). */
        openTimerFocus?: (anchor?: HTMLElement) => void;
    }>(),
    { canAddNote: false, layout: 'default', canCreateTask: false }
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

async function quickCreateProject(body: CreateProjectBody) {
    const p = await props.createProject(body);
    if (p) {
        setBillableDefaultForProject();
        if (props.isActive) {
            emit('updateTimeEntry');
        }
    }
    return p;
}

const showQuickProjectCreate = ref(false);
const showQuickTaskCreate = ref(false);
const quickTaskCreateProjectId = ref('');

const quickTaskCreateDefaultProjectId = computed(() => {
    const id = currentTimeEntry.value.project_id;
    if (id != null && id !== '') {
        return id;
    }
    const active = props.projects.filter((p) => !p.is_archived);
    return active[0]?.id ?? props.projects[0]?.id ?? '';
});

const canShowQuickCreateMenu = computed(
    () =>
        props.canCreateProject ||
        (props.canCreateTask && quickTaskCreateDefaultProjectId.value !== '')
);

function openQuickTaskCreateModal() {
    const pid = quickTaskCreateDefaultProjectId.value;
    if (!pid) {
        return;
    }
    quickTaskCreateProjectId.value = pid;
    showQuickTaskCreate.value = true;
}

function onQuickTaskCreated(task: Task) {
    currentTimeEntry.value.project_id = task.project_id;
    currentTimeEntry.value.task_id = task.id;
    setBillableDefaultForProject();
    if (props.isActive) {
        emit('updateTimeEntry');
    }
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
        if (!blockRefocus.value && props.layout !== 'focus') {
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

type FocusQuickPickRow = {
    entry: TimeEntry | null;
    project: Project | undefined;
    task: Task | undefined;
    key: string;
};

/**
 * Focus bar: recent project+task pairs first, then every open task per active project,
 * then a project-only chip when a project has no open tasks.
 */
const focusQuickPickRows = computed(() => {
    if (props.layout !== 'focus') {
        return [] as FocusQuickPickRow[];
    }

    const rows: FocusQuickPickRow[] = [];
    const seenKeys = new Set<string>();

    for (const entry of dedupeTimeEntriesByProjectTask(props.timeEntries, true)) {
        const ctxKey = timeEntryContextKeyForFocusPicker(entry);
        seenKeys.add(ctxKey);
        rows.push({
            entry,
            project: props.projects.find((p) => p.id === entry.project_id),
            task: props.tasks.find((t) => t.id === entry.task_id),
            key: `recent:${ctxKey}`,
        });
    }

    const activeProjects = [...props.projects]
        .filter((p) => !p.is_archived)
        .sort((a, b) => a.name.localeCompare(b.name));

    for (const p of activeProjects) {
        const openTasks = props.tasks
            .filter((t) => t.project_id === p.id && !t.is_done)
            .sort((a, b) => a.name.localeCompare(b.name));

        if (openTasks.length > 0) {
            for (const task of openTasks) {
                const ctxKey = timeEntryContextKeyForFocusPicker({
                    id: task.id,
                    project_id: p.id,
                    task_id: task.id,
                });
                if (seenKeys.has(ctxKey)) {
                    continue;
                }
                seenKeys.add(ctxKey);
                rows.push({
                    entry: null,
                    project: p,
                    task,
                    key: `t:${task.id}`,
                });
            }
        } else {
            const ctxKey = timeEntryContextKeyForFocusPicker({
                id: p.id,
                project_id: p.id,
                task_id: null,
            });
            if (seenKeys.has(ctxKey)) {
                continue;
            }
            seenKeys.add(ctxKey);
            rows.push({
                entry: null,
                project: p,
                task: undefined,
                key: `p:${p.id}`,
            });
        }
    }

    return rows;
});

const FOCUS_QUICK_PICK_SCROLL_STEP_PX = 200;

const focusQuickPickScrollEl = ref<HTMLElement | null>(null);
const canScrollFocusQuickPickLeft = ref(false);
const canScrollFocusQuickPickRight = ref(false);

function updateFocusQuickPickScrollArrows() {
    const el = focusQuickPickScrollEl.value;
    if (!el) {
        canScrollFocusQuickPickLeft.value = false;
        canScrollFocusQuickPickRight.value = false;
        return;
    }
    const { scrollLeft, scrollWidth, clientWidth } = el;
    const maxScroll = Math.max(0, scrollWidth - clientWidth);
    const epsilon = 2;
    canScrollFocusQuickPickLeft.value = scrollLeft > epsilon;
    canScrollFocusQuickPickRight.value = maxScroll > epsilon && scrollLeft < maxScroll - epsilon;
}

function scrollFocusQuickPick(delta: number) {
    focusQuickPickScrollEl.value?.scrollBy({ left: delta, behavior: 'smooth' });
}

useResizeObserver(focusQuickPickScrollEl, () => {
    updateFocusQuickPickScrollArrows();
});

watch(
    () => focusQuickPickRows.value,
    () => nextTick(() => updateFocusQuickPickScrollArrows()),
    { deep: true }
);

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

function isBlankId(id: string | null | undefined): boolean {
    return id == null || id === '';
}

/** Whether a quick-pick entry matches the timer's current project/task (for focus row highlight). */
function quickPickMatchesCurrentContext(entry: TimeEntry): boolean {
    const c = currentTimeEntry.value;
    const sameProject =
        (isBlankId(entry.project_id) && isBlankId(c.project_id)) ||
        entry.project_id === c.project_id;
    const sameTask =
        (isBlankId(entry.task_id) && isBlankId(c.task_id)) || entry.task_id === c.task_id;
    return sameProject && sameTask;
}

function focusQuickPickRowMatchesCurrent(row: FocusQuickPickRow): boolean {
    if (row.entry) {
        return quickPickMatchesCurrentContext(row.entry);
    }
    const c = currentTimeEntry.value;
    if (row.task && row.project) {
        return c.project_id === row.project.id && c.task_id === row.task.id;
    }
    if (row.project) {
        return c.project_id === row.project.id && isBlankId(c.task_id);
    }
    return false;
}

function focusQuickPickTooltip(row: FocusQuickPickRow): string {
    if (row.entry) {
        return recentChipLabel(row.entry);
    }
    if (row.project && row.task) {
        return `${row.project.name} › ${row.task.name}`;
    }
    return row.project?.name ?? '';
}

function applyFocusQuickPickRow(row: FocusQuickPickRow) {
    if (row.entry) {
        applyRecentTimeEntryContext(row.entry);
        return;
    }
    if (row.project && row.task) {
        currentTimeEntry.value.project_id = row.project.id;
        currentTimeEntry.value.task_id = row.task.id;
        setBillableDefaultForProject();
        if (props.isActive) {
            emit('updateTimeEntry');
        }
        return;
    }
    if (row.project) {
        currentTimeEntry.value.project_id = row.project.id;
        currentTimeEntry.value.task_id = null;
        setBillableDefaultForProject();
        if (props.isActive) {
            emit('updateTimeEntry');
        }
    }
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
                class="flex flex-col gap-3 p-3 lg:flex-row lg:items-center lg:justify-between lg:gap-4 border-t border-card-background-separator">
                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                    <div class="flex min-w-0 shrink-0 items-center gap-1">
                        <TimeTrackerProjectTaskDropdown
                            v-model:project="currentTimeEntry.project_id"
                            v-model:task="currentTimeEntry.task_id"
                            trigger-variant="chevronPill"
                            variant="outline"
                            align="start"
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
                        <DropdownMenu v-if="canShowQuickCreateMenu">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="inline-flex h-7 w-7 shrink-0 select-none items-center justify-center border border-input-border p-0 text-text-secondary"
                                    data-testid="timer_quick_create_menu"
                                    aria-label="Create project or task">
                                    <PlusIcon class="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start" class="min-w-[11rem]">
                                <DropdownMenuItem
                                    v-if="canCreateProject"
                                    class="flex cursor-pointer items-center gap-2"
                                    @click="showQuickProjectCreate = true">
                                    <FolderIcon class="h-4 w-4 shrink-0" />
                                    <span>New project</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="canCreateTask && quickTaskCreateDefaultProjectId"
                                    class="flex cursor-pointer items-center gap-2"
                                    @click="openQuickTaskCreateModal">
                                    <QueueListIcon class="h-4 w-4 shrink-0" />
                                    <span>New task</span>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                    <template v-if="focusQuickPickRows.length > 0">
                        <div class="relative min-w-0 flex-1">
                            <button
                                v-show="canScrollFocusQuickPickLeft"
                                type="button"
                                class="absolute left-1 top-1/2 z-10 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full border border-card-border/40 bg-card-background/75 text-text-primary shadow-sm backdrop-blur-sm transition hover:bg-card-background/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                aria-label="Scroll quick picks left"
                                @click="scrollFocusQuickPick(-FOCUS_QUICK_PICK_SCROLL_STEP_PX)">
                                <ChevronLeftIcon class="h-5 w-5 opacity-90" />
                            </button>
                            <button
                                v-show="canScrollFocusQuickPickRight"
                                type="button"
                                class="absolute right-1 top-1/2 z-10 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full border border-card-border/40 bg-card-background/75 text-text-primary shadow-sm backdrop-blur-sm transition hover:bg-card-background/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                aria-label="Scroll quick picks right"
                                @click="scrollFocusQuickPick(FOCUS_QUICK_PICK_SCROLL_STEP_PX)">
                                <ChevronRightIconSolid class="h-5 w-5 opacity-90" />
                            </button>
                            <div
                                ref="focusQuickPickScrollEl"
                                class="flex min-w-0 flex-nowrap items-center gap-1.5 overflow-x-auto pb-0.5 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                                @scroll.passive="updateFocusQuickPickScrollArrows">
                                <TooltipProvider
                                    v-for="row in focusQuickPickRows"
                                    :key="`chip-focus-${row.key}`">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <button
                                                type="button"
                                                :class="
                                                    twMerge(
                                                        'shrink-0 max-w-[min(12rem,100%)] rounded-md border border-transparent text-left ring-0 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                                        focusQuickPickRowMatchesCurrent(row) &&
                                                            'border-accent-300/50 bg-accent-50 shadow-sm dark:border-accent-400/60 dark:bg-accent-300/25 dark:shadow-[0_0_0_1px_rgba(var(--color-accent-400),0.22)]'
                                                    )
                                                "
                                                @click="applyFocusQuickPickRow(row)">
                                                <ProjectBadge
                                                    class="min-w-0 max-w-full"
                                                    size="base"
                                                    :name="row.project?.name"
                                                    :color="row.project?.color">
                                                    <div
                                                        v-if="row.project"
                                                        class="flex min-w-0 items-center space-x-0.5 lg:space-x-1">
                                                        <span
                                                            class="shrink-0 text-xs font-medium text-text-primary">
                                                            {{ row.project.name }}
                                                        </span>
                                                        <ChevronRightIcon
                                                            v-if="row.task"
                                                            class="h-4 w-4 shrink-0 text-text-secondary"></ChevronRightIcon>
                                                        <span
                                                            v-if="row.task"
                                                            class="min-w-0 truncate text-xs font-medium text-text-primary">
                                                            {{ row.task.name }}
                                                        </span>
                                                    </div>
                                                    <div
                                                        v-else-if="row.entry"
                                                        class="min-w-0 truncate text-xs font-medium text-text-primary">
                                                        {{ recentChipLabel(row.entry) }}
                                                    </div>
                                                </ProjectBadge>
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <p class="max-w-sm">{{ focusQuickPickTooltip(row) }}</p>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </div>
                        </div>
                    </template>
                </div>
                <div
                    class="flex flex-wrap items-center gap-1 sm:gap-2 lg:justify-end shrink-0">
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
                <div class="flex w-[130px] min-w-0 shrink items-center gap-1 @2xl:w-auto">
                    <TimeTrackerProjectTaskDropdown
                        v-model:project="currentTimeEntry.project_id"
                        v-model:task="currentTimeEntry.task_id"
                        variant="outline"
                        class="min-w-0 flex-1"
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
                    <DropdownMenu v-if="canShowQuickCreateMenu">
                        <DropdownMenuTrigger as-child>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-9 w-9 shrink-0 p-0 text-text-secondary"
                                data-testid="timer_quick_create_menu"
                                aria-label="Create project or task">
                                <PlusIcon class="h-5 w-5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="min-w-[11rem]">
                            <DropdownMenuItem
                                v-if="canCreateProject"
                                class="flex cursor-pointer items-center gap-2"
                                @click="showQuickProjectCreate = true">
                                <FolderIcon class="h-4 w-4 shrink-0" />
                                <span>New project</span>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="canCreateTask && quickTaskCreateDefaultProjectId"
                                class="flex cursor-pointer items-center gap-2"
                                @click="openQuickTaskCreateModal">
                                <QueueListIcon class="h-4 w-4 shrink-0" />
                                <span>New task</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
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
    <ProjectCreateModal
        v-model:show="showQuickProjectCreate"
        :create-client="createClient"
        :enable-estimated-time="enableEstimatedTime"
        :organization-billable-rate="organizationBillableRate"
        :currency="currency"
        :clients="clients"
        :create-project="quickCreateProject"></ProjectCreateModal>
    <TaskCreateModal
        v-model:show="showQuickTaskCreate"
        :project-id="quickTaskCreateProjectId"
        @created="onQuickTaskCreated"></TaskCreateModal>
</template>

<style scoped></style>
