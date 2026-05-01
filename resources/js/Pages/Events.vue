<script setup lang="ts">
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import Card from '@/Components/Common/Card.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';
import { CalendarDaysIcon, PlusIcon } from '@heroicons/vue/20/solid';
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
import { getCalendarEventFocusBucket } from '@/utils/timerFocusCalendarEventSort';
import { useTimestamp } from '@vueuse/core';

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
const projectFilter = ref('');
const taskFilter = ref('');
const rangePresetDays = ref<7 | 30 | 90>(30);

watch(projectFilter, () => {
    if (!taskFilter.value) {
        return;
    }
    const t = tasks.value.find((x) => x.id === taskFilter.value);
    if (t && projectFilter.value && t.project_id !== projectFilter.value) {
        taskFilter.value = '';
    }
});

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
    if (taskFilter.value) {
        q.task_id = taskFilter.value;
    } else if (projectFilter.value) {
        q.project_id = projectFilter.value;
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

const activeProjects = computed(() =>
    projects.value.filter((p) => !p.is_archived).sort((a, b) => a.name.localeCompare(b.name))
);

const tasksForSelect = computed(() => {
    let list = tasks.value.filter((t) => !t.is_done);
    if (projectFilter.value) {
        list = list.filter((t) => t.project_id === projectFilter.value);
    }
    return list.sort((a, b) => a.name.localeCompare(b.name));
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
    return `From today through ${endDay}`;
});
</script>

<template>
    <AppLayout title="Events" data-testid="events_view">
        <template v-if="canViewCalendarEvents()">
            <MainContainer
                class="flex flex-wrap items-center justify-between gap-4 border-b border-default-background-separator py-5">
                <PageTitle :icon="CalendarDaysIcon" title="Events" />
                <SecondaryButton
                    v-if="canCreateCalendarEvents()"
                    :icon="PlusIcon"
                    data-testid="events_new_button"
                    @click="openCreateModal"
                    >New event
                </SecondaryButton>
            </MainContainer>
            <MainContainer class="pt-6">
                <p class="mb-4 max-w-3xl text-sm text-text-secondary">
                    {{ rangeSummary }}. Use filters to narrow the list; title search applies to loaded
                    events only.
                </p>
                <FieldGroup class="mb-6 max-w-4xl">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <Field>
                            <FieldLabel for="eventsSearch">Search</FieldLabel>
                            <TextInput
                                id="eventsSearch"
                                v-model="searchInput"
                                type="search"
                                class="block w-full"
                                placeholder="Title or description…"
                                autocomplete="off" />
                        </Field>
                        <Field>
                            <FieldLabel for="eventsVisibility">Visibility</FieldLabel>
                            <select
                                id="eventsVisibility"
                                v-model="visibilityFilter"
                                class="block w-full rounded-md border border-default bg-card-background px-3 py-2 text-sm text-text-primary shadow-sm focus:border-transparent focus:ring-2 focus:ring-ring">
                                <option value="">All</option>
                                <option value="private">Private</option>
                                <option value="shared">Shared</option>
                            </select>
                        </Field>
                        <Field>
                            <FieldLabel for="eventsRange">Upcoming window</FieldLabel>
                            <select
                                id="eventsRange"
                                v-model.number="rangePresetDays"
                                class="block w-full rounded-md border border-default bg-card-background px-3 py-2 text-sm text-text-primary shadow-sm focus:border-transparent focus:ring-2 focus:ring-ring">
                                <option :value="7">Next 7 days</option>
                                <option :value="30">Next 30 days</option>
                                <option :value="90">Next 90 days</option>
                            </select>
                        </Field>
                        <Field>
                            <FieldLabel for="eventsProject">Project</FieldLabel>
                            <select
                                id="eventsProject"
                                v-model="projectFilter"
                                class="block w-full rounded-md border border-default bg-card-background px-3 py-2 text-sm text-text-primary shadow-sm focus:border-transparent focus:ring-2 focus:ring-ring">
                                <option value="">All projects</option>
                                <option v-for="p in activeProjects" :key="p.id" :value="p.id">
                                    {{ p.name }}
                                </option>
                            </select>
                        </Field>
                        <Field>
                            <FieldLabel for="eventsTask">Task</FieldLabel>
                            <select
                                id="eventsTask"
                                v-model="taskFilter"
                                class="block w-full rounded-md border border-default bg-card-background px-3 py-2 text-sm text-text-primary shadow-sm focus:border-transparent focus:ring-2 focus:ring-ring">
                                <option value="">All tasks</option>
                                <option v-for="t in tasksForSelect" :key="t.id" :value="t.id">
                                    {{ t.name }}
                                </option>
                            </select>
                        </Field>
                    </div>
                </FieldGroup>
                <Card>
                    <div v-if="isLoading" class="p-8 text-center text-sm text-text-secondary">
                        Loading…
                    </div>
                    <div
                        v-else-if="!groupedByDay.length"
                        class="p-8 text-center text-sm text-text-secondary"
                        data-testid="events_empty">
                        No events in this range
                        <template v-if="canCreateCalendarEvents()">
                            — use New event to add one.</template
                        >.
                    </div>
                    <div v-else class="divide-y divide-border/60" data-testid="events_list">
                        <section
                            v-for="[dayKey, dayEvents] in groupedByDay"
                            :key="dayKey"
                            class="px-4 py-5 sm:px-6">
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-text-tertiary">
                                {{ formatDayGroupLabel(dayKey) }}
                            </h2>
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
            </MainContainer>

            <CalendarEventFormModal
                v-if="canCreateCalendarEvents() || editingEvent"
                v-model:show="eventModalOpen"
                :projects="projects"
                :tasks="tasks"
                :editing="editingEvent"
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
        </template>
        <MainContainer v-else class="py-10">
            <p class="text-sm text-text-secondary">You do not have permission to view events.</p>
        </MainContainer>
    </AppLayout>
</template>
