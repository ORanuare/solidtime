<script setup lang="ts">
import CardTitle from '@/packages/ui/src/CardTitle.vue';
import Card from '@/Components/Common/Card.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';
import { CalendarDaysIcon, PlusIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import {
    canCreateCalendarEvents,
    canViewCalendarEvents,
} from '@/utils/permissions';
import { computed, ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { getCurrentOrganizationId } from '@/utils/useUser';
import {
    useOrgCalendarEventsInRangeQuery,
    type OrgCalendarEventsListQueries,
} from '@/utils/useOrgCalendarEventsQuery';
import { useOrgCalendarEventsMutations } from '@/utils/useOrgCalendarEventsMutations';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { getDayJsInstance } from '@/packages/ui/src/utils/time';
import { getUserTimezone } from '@/packages/ui/src/utils/settings';
import type { CreateOrgCalendarEventBody, OrgCalendarEvent } from '@/packages/api/src';
import FocusCalendarEventCard from '@/Components/Common/CalendarEvent/FocusCalendarEventCard.vue';
import CalendarEventFormModal from '@/packages/ui/src/FullCalendar/CalendarEventFormModal.vue';
import CalendarEventDetailModal from '@/packages/ui/src/FullCalendar/CalendarEventDetailModal.vue';
import { getCalendarEventFocusBucket, isCalendarEventInProjectDetailScope } from '@/utils/timerFocusCalendarEventSort';
import { useTimestamp } from '@vueuse/core';

const props = defineProps<{
    projectId: string;
    /** When set (e.g. task row filter), list is project/task-scoped like Notes. */
    taskFilterId?: string | null | undefined;
}>();

const emit = defineEmits<{
    (e: 'clear-task-filter'): void;
}>();

const searchInput = ref('');
const debouncedSearch = ref('');
const runDebouncedSearch = useDebounceFn((v: string) => {
    debouncedSearch.value = v;
}, 300);

watch(
    searchInput,
    (v) => {
        runDebouncedSearch(v);
    },
    { immediate: true }
);

const visibilityFilter = ref<'private' | 'shared' | ''>('');
const rangePresetDays = ref<7 | 30 | 90>(30);

const rangeIso = computed(() => {
    const dayjs = getDayJsInstance();
    const tz = getUserTimezone();
    const start = dayjs().tz(tz).startOf('day').utc().format();
    const end = dayjs()
        .tz(tz)
        .add(rangePresetDays.value, 'day')
        .endOf('day')
        .utc()
        .format();
    return { start, end };
});

const rangeStartIso = computed(() => rangeIso.value.start);
const rangeEndIso = computed(() => rangeIso.value.end);

const listQueries = computed((): OrgCalendarEventsListQueries => {
    const q: OrgCalendarEventsListQueries = {};
    if (visibilityFilter.value) {
        q.visibility = visibilityFilter.value;
    }
    if (props.taskFilterId) {
        q.task_id = props.taskFilterId;
    } else {
        q.project_id = props.projectId;
    }
    return q;
});

const eventsQueryEnabled = computed(() => canViewCalendarEvents());

const { data: rawEvents, isLoading } = useOrgCalendarEventsInRangeQuery(
    rangeStartIso,
    rangeEndIso,
    listQueries,
    eventsQueryEnabled
);

const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();
const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);

const orgTimeFormat = computed(
    () => organization.value?.time_format ?? ('24-hours' as const)
);

const taskFilterName = computed(() => {
    if (!props.taskFilterId) {
        return '';
    }
    return tasks.value.find((t) => t.id === props.taskFilterId)?.name ?? '';
});

function dayKeyForEvent(ev: OrgCalendarEvent): string {
    const dayjs = getDayJsInstance();
    if (!ev.starts_at) {
        return '';
    }
    return dayjs.utc(ev.starts_at).tz(getUserTimezone()).format('YYYY-MM-DD');
}

function formatDayGroupLabel(dayKey: string): string {
    if (!dayKey) {
        return '';
    }
    const dayjs = getDayJsInstance();
    const parsed = dayjs.tz(dayKey, 'YYYY-MM-DD', getUserTimezone());
    if (!parsed.isValid()) {
        return dayKey;
    }
    return parsed.format('dddd, MMMM D, YYYY');
}

const focusClock = useTimestamp({ interval: 60_000 });

function eventIsOngoing(ev: OrgCalendarEvent): boolean {
    return getCalendarEventFocusBucket(ev, focusClock.value) === 'active';
}

const displayedEvents = computed(() => {
    let list = [...(rawEvents.value ?? [])].filter((ev) => ev.starts_at);
    list = list.filter((ev) =>
        isCalendarEventInProjectDetailScope(ev, props.projectId, props.taskFilterId)
    );
    const q = debouncedSearch.value.trim().toLowerCase();
    if (q) {
        list = list.filter(
            (ev) =>
                (ev.title ?? '').toLowerCase().includes(q) ||
                (ev.description ?? '').toLowerCase().includes(q)
        );
    }
    list.sort((a, b) => (a.starts_at ?? '').localeCompare(b.starts_at ?? ''));
    return list;
});

const groupedByDay = computed(() => {
    const map = new Map<string, OrgCalendarEvent[]>();
    for (const ev of displayedEvents.value) {
        const k = dayKeyForEvent(ev);
        if (!k) {
            continue;
        }
        if (!map.has(k)) {
            map.set(k, []);
        }
        map.get(k)!.push(ev);
    }
    return [...map.entries()].sort(([a], [b]) => a.localeCompare(b));
});

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

const rangeSummary = computed(() => {
    const dayjs = getDayJsInstance();
    const d = dayjs().tz(getUserTimezone());
    const endDay = d.add(rangePresetDays.value, 'day').format('MMM D, YYYY');
    return `Through ${endDay}`;
});
</script>

<template>
    <div data-testid="project_show_events">
        <CardTitle title="Events" :icon="CalendarDaysIcon">
            <template #actions>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <div
                        v-if="taskFilterId"
                        class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-border-secondary bg-tertiary pl-2.5 pr-1 py-1 text-sm text-text-primary dark:bg-secondary">
                        <span class="text-text-tertiary shrink-0">Task</span>
                        <span class="min-w-0 truncate font-medium" :title="taskFilterName">
                            {{ taskFilterName || '…' }}
                        </span>
                        <button
                            type="button"
                            class="shrink-0 rounded p-1 text-text-secondary hover:bg-white/5 hover:text-text-primary"
                            aria-label="Show all project events"
                            @click="emit('clear-task-filter')">
                            <XMarkIcon class="h-4 w-4" />
                        </button>
                    </div>
                    <SecondaryButton
                        v-if="canCreateCalendarEvents()"
                        :icon="PlusIcon"
                        data-testid="project_show_events_new"
                        @click="openCreateModal">
                        New event
                    </SecondaryButton>
                </div>
            </template>
        </CardTitle>
        <p class="mt-3 max-w-3xl text-sm text-text-secondary">
            {{ rangeSummary }} (loaded window). Client search applies to this list only.
        </p>
        <FieldGroup class="mt-3 max-w-4xl">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Field>
                    <FieldLabel for="projectShowEventsSearch">Search</FieldLabel>
                    <TextInput
                        id="projectShowEventsSearch"
                        v-model="searchInput"
                        type="search"
                        class="block w-full"
                        placeholder="Title or description…"
                        autocomplete="off" />
                </Field>
                <Field>
                    <FieldLabel for="projectShowEventsVisibility">Visibility</FieldLabel>
                    <select
                        id="projectShowEventsVisibility"
                        v-model="visibilityFilter"
                        class="block w-full rounded-md border border-default bg-card-background px-3 py-2 text-sm text-text-primary shadow-sm focus:border-transparent focus:ring-2 focus:ring-ring">
                        <option value="">All</option>
                        <option value="private">Private</option>
                        <option value="shared">Shared</option>
                    </select>
                </Field>
                <Field>
                    <FieldLabel for="projectShowEventsRange">Upcoming window</FieldLabel>
                    <select
                        id="projectShowEventsRange"
                        v-model.number="rangePresetDays"
                        class="block w-full rounded-md border border-default bg-card-background px-3 py-2 text-sm text-text-primary shadow-sm focus:border-transparent focus:ring-2 focus:ring-ring">
                        <option :value="7">Next 7 days</option>
                        <option :value="30">Next 30 days</option>
                        <option :value="90">Next 90 days</option>
                    </select>
                </Field>
            </div>
        </FieldGroup>
        <Card class="mt-3">
            <div v-if="isLoading" class="p-8 text-center text-sm text-text-secondary">Loading…</div>
            <div
                v-else-if="!groupedByDay.length"
                class="p-8 text-center text-sm text-text-secondary"
                data-testid="project_show_events_empty">
                No events in this range for this project
                <template v-if="canCreateCalendarEvents()"> — use New event to add one.</template>.
            </div>
            <div v-else class="divide-y divide-border/60" data-testid="project_show_events_list">
                <section
                    v-for="[dayKey, dayEvents] in groupedByDay"
                    :key="dayKey"
                    class="px-4 py-4 sm:px-6">
                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-text-tertiary">
                        {{ formatDayGroupLabel(dayKey) }}
                    </h3>
                    <ul class="flex list-none flex-col gap-2">
                        <li
                            v-for="ev in dayEvents"
                            :key="ev.id"
                            class="relative pl-2.5 before:pointer-events-none before:absolute before:bottom-2 before:left-0 before:top-2 before:w-[3px] before:rounded-full"
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
                </section>
            </div>
        </Card>

        <CalendarEventFormModal
            v-if="canCreateCalendarEvents() || editingEvent"
            v-model:show="eventModalOpen"
            :projects="projects"
            :tasks="tasks"
            :editing="editingEvent"
            :default-project-id="projectId"
            :default-task-id="taskFilterId ?? null"
            :allow-pick-attachment="true"
            @save-create="onSaveCreate"
            @save-update="onSaveUpdate" />

        <CalendarEventDetailModal
            v-if="detailEvent"
            v-model:show="detailModalOpen"
            :calendar-event="detailEvent"
            :project="projectForEvent(detailEvent)"
            :task="taskForEvent(detailEvent)"
            :org-time-format="orgTimeFormat"
            @edit="onDetailEdit"
            @delete="onDetailDelete" />
    </div>
</template>
