<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useLocalStorage } from '@vueuse/core';
import { twMerge } from 'tailwind-merge';
import { PlusIcon } from '@heroicons/vue/20/solid';
import CalendarEventFormModal from '@/packages/ui/src/FullCalendar/CalendarEventFormModal.vue';
import CalendarEventDetailModal from '@/packages/ui/src/FullCalendar/CalendarEventDetailModal.vue';
import FocusCalendarEventCard from '@/Components/Common/CalendarEvent/FocusCalendarEventCard.vue';
import { useTimerNoteScope } from '@/utils/useTimerNoteScope';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { useOrgCalendarEventsMutations } from '@/utils/useOrgCalendarEventsMutations';
import { useTimerFocusCalendarEventsQuery } from '@/utils/useTimerFocusCalendarEventsQuery';
import { useTimerFocus } from '@/utils/useTimerFocus';
import {
    sortCalendarEventsForTimerFocus,
    isWorkspaceCalendarEvent,
    isProjectOnlyCalendarEvent,
    getCalendarEventFocusBucket,
} from '@/utils/timerFocusCalendarEventSort';
import {
    canCreateCalendarEvents,
    canViewCalendarEvents,
} from '@/utils/permissions';
import { getCurrentOrganizationId } from '@/utils/useUser';
import {
    getNoteListFilterPillStyle,
    type TimerFocusNotesListMode,
} from '@/utils/noteNotablePillStyle';
import type { CreateOrgCalendarEventBody, OrgCalendarEvent } from '@/packages/api/src';
import { useTimestamp } from '@vueuse/core';
import { CalendarDaysIcon } from '@heroicons/vue/24/outline';
const { isTimerFocusOpen } = useTimerFocus();

const props = withDefaults(
    defineProps<{
        /** Desktop: overlay chip. Narrow screens: full-width in scroll flow. */
        layout?: 'floating' | 'embedded';
    }>(),
    { layout: 'floating' }
);

const shellOuterClass = computed(() =>
    props.layout === 'embedded'
        ? 'relative z-10 w-full max-w-full'
        : twMerge(
              'pointer-events-none z-10 lg:absolute lg:bottom-auto lg:left-3 lg:top-5 lg:w-[min(384px,calc(50%-1rem))] xl:left-4 xl:top-6 xl:w-[min(408px,calc(50%-1.25rem))]'
          )
);

const cardMaxHeightClass = computed(() =>
    props.layout === 'embedded'
        ? 'max-h-[min(260px,38vh)]'
        : 'max-h-[min(280px,calc(42vh))]'
);

const { listProjectId, listTaskId, formProjectId, formTaskId } = useTimerNoteScope();

const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();
const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);

const orgTimeFormat = computed(
    () => organization.value?.time_format ?? ('24-hours' as const)
);

/** Minute-resolution clock so “in 5m” labels stay plausible */
const focusClock = useTimestamp({ interval: 60_000 });

const listMode = useLocalStorage<TimerFocusNotesListMode>(
    'solidtime/timer-focus-events-list-mode',
    'all'
);

const resolvedProjectId = computed((): string | undefined => {
    if (listProjectId.value) {
        return listProjectId.value;
    }
    if (listTaskId.value) {
        return tasks.value.find((t) => t.id === listTaskId.value)?.project_id;
    }
    return undefined;
});

const apiListQueries = computed(() => {
    if (listMode.value === 'project' && resolvedProjectId.value) {
        return { project_id: resolvedProjectId.value };
    }
    if (listMode.value === 'task' && listTaskId.value) {
        return { task_id: listTaskId.value };
    }
    return undefined;
});

const { data: rawEvents, isLoading } = useTimerFocusCalendarEventsQuery(
    isTimerFocusOpen,
    apiListQueries
);

const visibleEventsForFocus = computed(() => {
    const allTasks = tasks.value;
    const allProjects = projects.value;
    let list = (rawEvents.value ?? []).filter((ev: OrgCalendarEvent) => {
        if (ev.task_id) {
            const task = allTasks.find((t) => t.id === ev.task_id);
            if (task?.is_done) {
                return false;
            }
            if (task?.project_id) {
                const p = allProjects.find((x) => x.id === task.project_id);
                if (p?.is_archived) {
                    return false;
                }
            }
            return true;
        }
        if (ev.project_id) {
            const p = allProjects.find((x) => x.id === ev.project_id);
            if (p?.is_archived) {
                return false;
            }
        }
        return true;
    });
    if (listMode.value === 'workspace') {
        list = list.filter((ev) => isWorkspaceCalendarEvent(ev));
    } else if (listMode.value === 'project') {
        const pid = resolvedProjectId.value;
        if (pid) {
            list = list.filter((ev) => ev.project_id === pid && isProjectOnlyCalendarEvent(ev));
        } else {
            list = list.filter((ev) => isProjectOnlyCalendarEvent(ev));
        }
    } else if (listMode.value === 'task') {
        const tid = listTaskId.value;
        if (tid) {
            list = list.filter((ev) => ev.task_id === tid);
        } else {
            list = list.filter((ev) => Boolean(ev.task_id));
        }
    }
    return list;
});

const sortProjectId = computed(() => formProjectId.value ?? resolvedProjectId.value);

const sortedEvents = computed(() =>
    sortCalendarEventsForTimerFocus(
        visibleEventsForFocus.value,
        sortProjectId.value,
        formTaskId.value,
        focusClock.value
    )
);

function eventIsOngoing(ev: OrgCalendarEvent) {
    return getCalendarEventFocusBucket(ev, focusClock.value) === 'active';
}

const listSummaryLine = computed(() => {
    const n = sortedEvents.value.length;
    if (!n) {
        return 'No events';
    }
    return n === 1 ? '1 event' : `${n} events`;
});

const workspaceDisplayName = computed(() => organization.value?.name ?? 'Workspace');

const timerProjectName = computed(() => {
    const id = resolvedProjectId.value;
    if (!id) {
        return null;
    }
    return projects.value.find((p) => p.id === id)?.name ?? null;
});

const timerTaskName = computed(() => {
    const id = listTaskId.value;
    if (!id) {
        return null;
    }
    return tasks.value.find((t) => t.id === id)?.name ?? null;
});

const assignmentFilterKeys = ['workspace', 'project', 'task'] as const;

const pillButtonLayout =
    'inline-flex min-h-[2rem] w-full items-center justify-center gap-1.5 rounded-full border px-2 py-1 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

function assignmentRowHint(key: 'workspace' | 'project' | 'task') {
    if (key === 'workspace') {
        return 'Workspace-only events. Click again to show all events.';
    }
    if (key === 'project') {
        return resolvedProjectId.value
            ? 'Events attached to the timer project only (not tasks). Click again to show all events.'
            : 'Events attached to a project only (not tasks). Click again to show all events.';
    }
    return listTaskId.value
        ? 'Events on the timer task. Click again to show all events.'
        : 'Events linked to any task. Click again to show all events.';
}

function assignmentFilterTitle(key: (typeof assignmentFilterKeys)[number]) {
    const detail =
        key === 'workspace'
            ? workspaceDisplayName.value
            : key === 'project'
              ? timerProjectName.value ?? 'Any project'
              : timerTaskName.value ?? 'Any task';
    return `${assignmentRowHint(key)} — ${detail}`;
}

function assignmentPillClass(key: (typeof assignmentFilterKeys)[number]) {
    const s = getNoteListFilterPillStyle(key);
    if (listMode.value === key) {
        return twMerge(pillButtonLayout, s.chipClass);
    }
    return twMerge(
        pillButtonLayout,
        'border-border-secondary bg-tertiary/40 text-text-secondary dark:bg-secondary/40'
    );
}

function assignmentPillIconClass(key: (typeof assignmentFilterKeys)[number]) {
    const s = getNoteListFilterPillStyle(key);
    if (listMode.value === key) {
        return twMerge('h-3.5 w-3.5 shrink-0', s.iconClass);
    }
    return twMerge('h-3.5 w-3.5 shrink-0', s.iconClass, 'opacity-80');
}

function setListMode(mode: 'workspace' | 'project' | 'task') {
    if (listMode.value === mode) {
        listMode.value = 'all';
        return;
    }
    listMode.value = mode;
}

function eventAttachmentStripeClass(ev: OrgCalendarEvent) {
    const level = ev.task_id ? 'task' : ev.project_id ? 'project' : 'workspace';
    if (level === 'workspace') {
        return 'before:bg-violet-500 dark:before:bg-violet-400';
    }
    if (level === 'project') {
        return 'before:bg-sky-500 dark:before:bg-sky-400';
    }
    return 'before:bg-emerald-500 dark:before:bg-emerald-400';
}

function projectForEvent(ev: OrgCalendarEvent) {
    if (!ev.project_id) {
        return undefined;
    }
    return projects.value.find((p) => p.id === ev.project_id);
}

function taskForEvent(ev: OrgCalendarEvent) {
    if (!ev.task_id) {
        return undefined;
    }
    return tasks.value.find((t) => t.id === ev.task_id);
}

const {
    createOrgCalendarEvent,
    updateOrgCalendarEvent,
    deleteOrgCalendarEvent,
} = useOrgCalendarEventsMutations();

const eventModalOpen = ref(false);
const editingEvent = ref<OrgCalendarEvent | null>(null);

const detailModalOpen = ref(false);
const detailEvent = ref<OrgCalendarEvent | null>(null);

watch(eventModalOpen, (open) => {
    if (!open) {
        editingEvent.value = null;
    }
});

watch(detailModalOpen, (open) => {
    if (!open) {
        detailEvent.value = null;
    }
});

function openCreateModal() {
    editingEvent.value = null;
    eventModalOpen.value = true;
}

async function onDetailDelete(ev: OrgCalendarEvent) {
    await deleteOrgCalendarEvent(ev.id);
    detailModalOpen.value = false;
    detailEvent.value = null;
}

function openDetailModal(ev: OrgCalendarEvent) {
    detailEvent.value = ev;
    detailModalOpen.value = true;
}

function onDetailEdit(ev: OrgCalendarEvent) {
    editingEvent.value = ev;
    eventModalOpen.value = true;
}

async function onSaveCreate(body: CreateOrgCalendarEventBody) {
    await createOrgCalendarEvent(body);
}

async function onSaveUpdate(payload: { id: string; body: Record<string, unknown> }) {
    await updateOrgCalendarEvent(payload);
}
</script>

<template>
    <div
        v-if="canViewCalendarEvents() || canCreateCalendarEvents()"
        :class="shellOuterClass">
        <div
            :class="
                twMerge(
                    'pointer-events-auto flex min-h-0 w-full flex-col overflow-hidden rounded-2xl border border-card-border bg-card-background/90 shadow-2xl ring-1 ring-black/5 backdrop-blur-md dark:bg-card-background/85 dark:ring-white/10',
                    cardMaxHeightClass
                )
            "
            data-testid="timer_focus_events">
            <div
                class="shrink-0 space-y-2 border-b border-border/80 bg-gradient-to-b from-secondary/25 via-card-background/95 to-card-background px-2 py-2 dark:from-secondary/15 dark:via-card-background/90 dark:to-card-background/90">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 flex-1 items-center gap-1.5">
                        <CalendarDaysIcon
                            class="h-4 w-4 shrink-0 text-accent-600 dark:text-accent-400"
                            aria-hidden="true" />
                        <div class="min-w-0 leading-tight">
                            <span
                                class="block text-[0.52rem] font-bold uppercase tracking-[0.12em] text-text-tertiary">
                                Schedule
                            </span>
                            <span
                                class="mt-0.5 block text-[0.58rem] tabular-nums text-text-tertiary/90">
                                {{ listSummaryLine }}
                            </span>
                        </div>
                    </div>
                    <button
                        v-if="canCreateCalendarEvents()"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-md px-2 py-1 text-xs text-text-tertiary transition hover:bg-white/5 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        data-testid="timer_focus_events_add"
                        aria-label="Create calendar event"
                        title="Create calendar event"
                        @click="openCreateModal">
                        <PlusIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                        Add
                    </button>
                </div>

                <div
                    role="group"
                    aria-label="Filter events by workspace, project, or task"
                    data-testid="timer_focus_events_list_mode"
                    class="grid grid-cols-3 gap-1.5">
                    <button
                        v-for="key in assignmentFilterKeys"
                        :key="key"
                        type="button"
                        :class="assignmentPillClass(key)"
                        :aria-pressed="listMode === key"
                        :title="assignmentFilterTitle(key)"
                        @click="setListMode(key)">
                        <component
                            :is="getNoteListFilterPillStyle(key).icon"
                            :class="assignmentPillIconClass(key)"
                            aria-hidden="true" />
                        <span class="whitespace-nowrap">{{ getNoteListFilterPillStyle(key).shortLabel }}</span>
                    </button>
                </div>
            </div>

            <div
                class="flex min-h-0 flex-1 flex-col bg-gradient-to-b from-secondary/15 via-default-background/55 to-default-background/55 dark:from-secondary/10 dark:to-default-background/40 dark:via-default-background/40">
                <div
                    v-if="canViewCalendarEvents()"
                    class="min-h-0 flex-1 overflow-y-auto overflow-x-hidden px-2 py-2 [-webkit-overflow-scrolling:touch]">
                    <p v-if="isLoading" class="py-6 text-center text-xs text-text-secondary">Loading…</p>
                    <p
                        v-else-if="!sortedEvents.length"
                        class="py-6 text-center text-xs leading-relaxed text-text-secondary">
                        <template v-if="canCreateCalendarEvents()">
                            No events in this view. Use Add above.
                        </template>
                        <template v-else>No events in this view.</template>
                    </p>
                    <ul v-else class="flex list-none flex-col gap-1.5" data-testid="timer_focus_events_list">
                        <li
                            v-for="ev in sortedEvents"
                            :key="ev.id"
                            class="relative pl-2.5 before:pointer-events-none before:absolute before:left-0 before:top-2 before:bottom-2 before:w-[3px] before:rounded-full"
                            :class="eventAttachmentStripeClass(ev)">
                            <FocusCalendarEventCard
                                compact
                                interactive
                                :ongoing="eventIsOngoing(ev)"
                                :calendar-event="ev"
                                :project="projectForEvent(ev)"
                                :task="taskForEvent(ev)"
                                :org-time-format="orgTimeFormat"
                                @view-details="openDetailModal(ev)" />
                        </li>
                    </ul>
                </div>

                <p
                    v-if="!canViewCalendarEvents() && canCreateCalendarEvents()"
                    class="border-t border-border/80 px-2 py-2 text-center text-[0.65rem] leading-snug text-text-secondary">
                    You can create events, but listing them needs calendar view permission.
                </p>
            </div>
        </div>

        <CalendarEventFormModal
            v-if="canCreateCalendarEvents() || editingEvent"
            v-model:show="eventModalOpen"
            :projects="projects"
            :tasks="tasks"
            :editing="editingEvent"
            :default-project-id="formProjectId ?? null"
            :default-task-id="formTaskId ?? null"
            :allow-pick-attachment="true"
            @save-create="onSaveCreate"
            @save-update="onSaveUpdate" />

        <CalendarEventDetailModal
            v-if="detailEvent && canViewCalendarEvents()"
            v-model:show="detailModalOpen"
            :calendar-event="detailEvent"
            :project="projectForEvent(detailEvent)"
            :task="taskForEvent(detailEvent)"
            :org-time-format="orgTimeFormat"
            @edit="onDetailEdit"
            @delete="onDetailDelete" />
    </div>
</template>
