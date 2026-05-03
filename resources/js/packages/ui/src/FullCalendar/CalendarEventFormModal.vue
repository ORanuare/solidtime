<script setup lang="ts">
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import TextareaInput from '@/packages/ui/src/Input/TextareaInput.vue';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/packages/ui/src';
import Checkbox from '@/packages/ui/src/Input/Checkbox.vue';
import { BuildingOffice2Icon, FolderIcon } from '@heroicons/vue/20/solid';
import { ListBulletIcon } from '@heroicons/vue/16/solid';
import chroma from 'chroma-js';
import { computed, ref, watch } from 'vue';
import { twMerge } from 'tailwind-merge';
import { getDayJsInstance, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import TimePickerSimple from '@/packages/ui/src/Input/TimePickerSimple.vue';
import DatePicker from '@/packages/ui/src/Input/DatePicker.vue';
import type {
    CreateOrgCalendarEventBody,
    OrgCalendarEvent,
    Project,
    Task,
} from '@/packages/api/src';
import { eventAssignmentList } from '@/utils/orgCalendarEventAssignments';
import { NOTE_NOTABLE_LEVEL_PILL_STYLE } from '@/utils/noteNotablePillStyle';

const show = defineModel('show', { default: false });

const props = withDefaults(
    defineProps<{
        projects: Project[];
        tasks: Task[];
        editing?: OrgCalendarEvent | null;
        /** ISO UTC — prefilled start when opening create */
        initialStartsAt?: string | null;
        /** ISO UTC */
        initialEndsAt?: string | null;
        initialAllDay?: boolean;
        /** Timer/calendar context defaults */
        defaultProjectId?: string | null;
        defaultTaskId?: string | null;
        allowPickAttachment?: boolean;
        userId?: string | null;
    }>(),
    {
        editing: null,
        initialStartsAt: null,
        initialEndsAt: null,
        initialAllDay: false,
        defaultProjectId: null,
        defaultTaskId: null,
        allowPickAttachment: true,
        userId: null,
    }
);

const emit = defineEmits<{
    (e: 'save-create', body: CreateOrgCalendarEventBody): void;
    (
        e: 'save-update',
        payload: { id: string; body: Record<string, unknown> }
    ): void;
}>();

const saving = ref(false);

const title = ref('');
const description = ref('');
const visibility = ref<'private' | 'shared'>('shared');
const allDay = ref(false);
/** Localized timestamps for all-day range (start/end of calendar days). */
const allDayStartAt = ref<string | null>(null);
const allDayEndAt = ref<string | null>(null);
/** Localized timestamps for timed events (same pattern as time entry modals). */
const localStart = ref<string | null>(null);
const localEnd = ref<string | null>(null);

/** Selection order preserved for API payload stability. */
const selectedProjectIds = ref<string[]>([]);
const selectedTaskIds = ref<string[]>([]);

/** Picker lists: exclude archived projects and completed tasks (and tasks on archived projects). */
const linkableProjects = computed(() => props.projects.filter((p) => !p.is_archived));

const linkableTasks = computed(() =>
    props.tasks.filter((t) => {
        const p = props.projects.find((x) => x.id === t.project_id);
        return !t.is_done && p !== undefined && !p.is_archived;
    })
);

/** Editing: selections that point to archived projects or done / orphaned tasks — shown so they can be removed. */
const selectedUnavailableProjects = computed(() => {
    const out: Project[] = [];
    for (const id of selectedProjectIds.value) {
        const p = props.projects.find((x) => x.id === id);
        if (p?.is_archived) {
            out.push(p);
        }
    }
    return out.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
});

const selectedUnavailableTasks = computed(() => {
    const out: Task[] = [];
    for (const id of selectedTaskIds.value) {
        const t = props.tasks.find((x) => x.id === id);
        if (!t) {
            continue;
        }
        const p = props.projects.find((x) => x.id === t.project_id);
        if (t.is_done || p?.is_archived) {
            out.push(t);
        }
    }
    return out.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
});

const hasStaleLinkSelections = computed(
    () =>
        selectedUnavailableProjects.value.length > 0 || selectedUnavailableTasks.value.length > 0
);

const tasksSorted = computed(() =>
    [...linkableTasks.value].sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }))
);

const projectsSorted = computed(() =>
    [...linkableProjects.value].sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }))
);

const tasksGroupedByProject = computed(() => {
    const byPid = new Map<string, Task[]>();
    for (const t of tasksSorted.value) {
        if (!byPid.has(t.project_id)) {
            byPid.set(t.project_id, []);
        }
        byPid.get(t.project_id)!.push(t);
    }
    return projectsSorted.value
        .map((p) => ({
            project: p,
            tasks: (byPid.get(p.id) ?? []).sort((a, b) =>
                a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })
            ),
        }))
        .filter((g) => g.tasks.length > 0);
});

const hasAnyLinkSelection = computed(
    () => selectedProjectIds.value.length > 0 || selectedTaskIds.value.length > 0
);

const isWorkspaceOnlySelected = computed(() => !hasAnyLinkSelection.value);

function safeProjectColor(hex: string | undefined): chroma.Color {
    try {
        return chroma(hex || '#6B7280');
    } catch {
        return chroma('#6B7280');
    }
}

function projectChipSurface(project: Project, selected: boolean): Record<string, string> {
    if (!selected) {
        return {};
    }
    const c = safeProjectColor(project.color);
    return {
        borderColor: c.alpha(0.88).css(),
        backgroundColor: c.alpha(0.09).css(),
        boxShadow: `inset 0 0 0 1px ${c.alpha(0.28).css()}`,
    };
}

function projectChipButtonClass(selected: boolean): string {
    return twMerge(
        'inline-flex min-h-[2.5rem] min-w-0 max-w-full flex-1 basis-[calc(50%-0.25rem)] sm:basis-[calc(33.333%-0.25rem)] items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium transition',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        selected
            ? 'border-2 text-text-primary'
            : 'border border-border-secondary bg-card-background text-text-secondary hover:border-border-primary hover:bg-tertiary/35 dark:hover:bg-secondary/40',
    );
}

function taskChipButtonClass(selected: boolean): string {
    return twMerge(
        'inline-flex min-h-[2.125rem] min-w-0 max-w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-xs font-medium leading-snug transition',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
        selected
            ? 'border-2 border-emerald-500/55 bg-emerald-500/[0.08] text-emerald-950 shadow-sm dark:border-emerald-400/50 dark:bg-emerald-500/[0.1] dark:text-emerald-50'
            : 'border border-border-secondary/90 bg-card-background/80 text-text-secondary hover:border-emerald-500/35 hover:bg-emerald-500/[0.07]',
    );
}

function workspacePillClass(active: boolean): string {
    const ws = NOTE_NOTABLE_LEVEL_PILL_STYLE.workspace;
    return twMerge(
        'flex w-full min-w-0 items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        ws.chipClass,
        active
            ? 'ring-2 ring-violet-500 ring-offset-2 ring-offset-background shadow-sm dark:ring-violet-400'
            : 'opacity-[0.68] hover:opacity-100',
    );
}

function toggleProject(projectId: string) {
    const i = selectedProjectIds.value.indexOf(projectId);
    if (i >= 0) {
        selectedProjectIds.value = selectedProjectIds.value.filter((id) => id !== projectId);
        selectedTaskIds.value = selectedTaskIds.value.filter((tid) => {
            const t = props.tasks.find((x) => x.id === tid);
            return t?.project_id !== projectId;
        });
    } else {
        selectedProjectIds.value = [...selectedProjectIds.value, projectId];
    }
}

function toggleTask(taskId: string) {
    const i = selectedTaskIds.value.indexOf(taskId);
    if (i >= 0) {
        selectedTaskIds.value = selectedTaskIds.value.filter((id) => id !== taskId);
    } else {
        selectedTaskIds.value = [...selectedTaskIds.value, taskId];
    }
}

function selectWorkspaceOnly() {
    selectedProjectIds.value = [];
    selectedTaskIds.value = [];
}

function applyDefaultSelection() {
    selectWorkspaceOnly();
    if (props.defaultTaskId) {
        const t = props.tasks.find((x) => x.id === props.defaultTaskId);
        const p = t ? props.projects.find((x) => x.id === t.project_id) : undefined;
        if (t && !t.is_done && p && !p.is_archived) {
            selectedTaskIds.value = [props.defaultTaskId];
        }
        return;
    }
    if (props.defaultProjectId) {
        const p = props.projects.find((x) => x.id === props.defaultProjectId);
        if (p && !p.is_archived) {
            selectedProjectIds.value = [props.defaultProjectId];
        }
    }
}

function populateFromEditing(ev: OrgCalendarEvent) {
    title.value = ev.title;
    description.value = ev.description ?? '';
    visibility.value = ev.visibility === 'private' ? 'private' : 'shared';
    allDay.value = ev.all_day;
    if (ev.starts_at && ev.ends_at) {
        const s = getLocalizedDayJs(ev.starts_at);
        const en = getLocalizedDayJs(ev.ends_at);
        if (ev.all_day) {
            allDayStartAt.value = s.startOf('day').format();
            allDayEndAt.value = en.subtract(1, 'millisecond').startOf('day').format();
            localStart.value = s.format();
            localEnd.value = en.format();
        } else {
            localStart.value = s.format();
            localEnd.value = en.format();
            allDayStartAt.value = s.startOf('day').format();
            allDayEndAt.value = en.startOf('day').format();
        }
    }
    const list = eventAssignmentList(ev);
    const projects: string[] = [];
    const tasks: string[] = [];
    if (list.length > 0) {
        for (const a of list) {
            if (a.type === 'project') {
                projects.push(a.id);
            } else {
                tasks.push(a.id);
            }
        }
    } else if (ev.task_id) {
        tasks.push(ev.task_id);
    } else if (ev.project_id) {
        projects.push(ev.project_id);
    }
    selectedProjectIds.value = projects;
    selectedTaskIds.value = tasks;
}

function populateForCreate() {
    title.value = '';
    description.value = '';
    visibility.value = 'shared';
    allDay.value = props.initialAllDay ?? false;
    applyDefaultSelection();
    const start =
        props.initialStartsAt !== undefined && props.initialStartsAt !== null
            ? getLocalizedDayJs(props.initialStartsAt)
            : getLocalizedDayJs();
    const end =
        props.initialEndsAt !== undefined && props.initialEndsAt !== null
            ? getLocalizedDayJs(props.initialEndsAt)
            : start.add(1, 'hour');
    localStart.value = start.format();
    localEnd.value = end.format();
    allDayStartAt.value = start.startOf('day').format();
    if (allDay.value) {
        const inclusiveEnd = end.subtract(1, 'millisecond').startOf('day');
        const startDay = getLocalizedDayJs(allDayStartAt.value);
        allDayEndAt.value = inclusiveEnd.isBefore(startDay, 'day')
            ? allDayStartAt.value
            : inclusiveEnd.format();
    } else {
        allDayEndAt.value = end.startOf('day').format();
    }
}

watch(show, (open) => {
    if (!open) return;
    if (props.editing) {
        populateFromEditing(props.editing);
    } else {
        populateForCreate();
    }
});

function buildStartsEndsUtc(): { starts_at: string; ends_at: string } | null {
    const d = getDayJsInstance();
    if (allDay.value) {
        if (!allDayStartAt.value || !allDayEndAt.value) return null;
        const startDay = getLocalizedDayJs(allDayStartAt.value).format('YYYY-MM-DD');
        const endDay = getLocalizedDayJs(allDayEndAt.value).format('YYYY-MM-DD');
        const s = d(`${startDay}T00:00:00`).utc().format();
        const e = d(`${endDay}T00:00:00`).add(1, 'day').utc().format();
        return { starts_at: s, ends_at: e };
    }
    if (!localStart.value || !localEnd.value) return null;
    const starts_at = d(localStart.value).utc().format();
    const ends_at = d(localEnd.value).utc().format();
    if (ends_at <= starts_at) return null;
    return { starts_at, ends_at };
}

function setAllDay(next: boolean) {
    if (next === allDay.value) return;
    if (next) {
        const baseStart = localStart.value ? getLocalizedDayJs(localStart.value) : getLocalizedDayJs();
        let baseEnd = localEnd.value ? getLocalizedDayJs(localEnd.value) : baseStart.add(1, 'hour');
        if (!baseEnd.isAfter(baseStart)) {
            baseEnd = baseStart.endOf('day');
        }
        allDayStartAt.value = baseStart.startOf('day').format();
        allDayEndAt.value = baseEnd.startOf('day').format();
        if (
            getLocalizedDayJs(allDayEndAt.value).isBefore(getLocalizedDayJs(allDayStartAt.value), 'day')
        ) {
            allDayEndAt.value = allDayStartAt.value;
        }
    } else {
        const day = allDayStartAt.value
            ? getLocalizedDayJs(allDayStartAt.value).startOf('day')
            : getLocalizedDayJs().startOf('day');
        localStart.value = day.hour(9).minute(0).second(0).millisecond(0).format();
        localEnd.value = day.hour(10).minute(0).second(0).millisecond(0).format();
    }
    allDay.value = next;
}

/** API payloads: `{ type, id }[]`, deduped; projects first, then tasks. */
function assignmentsPayloadFromSelection(): { type: 'project' | 'task'; id: string }[] {
    const seen = new Set<string>();
    const out: { type: 'project' | 'task'; id: string }[] = [];
    for (const id of selectedProjectIds.value) {
        const k = `project:${id}`;
        if (!seen.has(k)) {
            seen.add(k);
            out.push({ type: 'project', id });
        }
    }
    for (const id of selectedTaskIds.value) {
        const k = `task:${id}`;
        if (!seen.has(k)) {
            seen.add(k);
            out.push({ type: 'task', id });
        }
    }
    return out;
}

function defaultAttachmentPayload(): Pick<
    CreateOrgCalendarEventBody,
    'assignments' | 'task_id' | 'project_id'
> {
    if (props.defaultTaskId) {
        const t = props.tasks.find((x) => x.id === props.defaultTaskId);
        const p = t ? props.projects.find((x) => x.id === t.project_id) : undefined;
        if (t && !t.is_done && p && !p.is_archived) {
            return { assignments: [{ type: 'task', id: props.defaultTaskId }] };
        }
    }
    if (props.defaultProjectId) {
        const p = props.projects.find((x) => x.id === props.defaultProjectId);
        if (p && !p.is_archived) {
            return { assignments: [{ type: 'project', id: props.defaultProjectId }] };
        }
    }
    return {};
}

const submitBlocked = computed(() => !title.value.trim());

async function onSubmit() {
    const range = buildStartsEndsUtc();
    if (!range || submitBlocked.value) return;
    saving.value = true;
    try {
        if (props.editing) {
            const body = buildPartialUpdate(range);
            emit('save-update', { id: props.editing.id, body });
        } else {
            const base = {
                title: title.value.trim(),
                description: description.value.trim() || null,
                starts_at: range.starts_at,
                ends_at: range.ends_at,
                all_day: allDay.value,
                visibility: visibility.value,
            };
            if (!props.allowPickAttachment) {
                emit('save-create', { ...base, ...defaultAttachmentPayload() } as CreateOrgCalendarEventBody);
            } else {
                const assignments = assignmentsPayloadFromSelection();
                emit('save-create', {
                    ...base,
                    ...(assignments.length > 0 ? { assignments } : {}),
                } as CreateOrgCalendarEventBody);
            }
        }
        show.value = false;
    } finally {
        saving.value = false;
    }
}

function buildPartialUpdate(range: { starts_at: string; ends_at: string }): Record<string, unknown> {
    const base: Record<string, unknown> = {
        title: title.value.trim(),
        description: description.value.trim() || null,
        starts_at: range.starts_at,
        ends_at: range.ends_at,
        all_day: allDay.value,
        visibility: visibility.value,
    };

    if (!props.allowPickAttachment) {
        return base;
    }

    return {
        ...base,
        reassign: true,
        assignments: assignmentsPayloadFromSelection(),
    };
}
</script>

<template>
    <DialogModal :show="show" max-width="lg" @close="show = false">
        <template #title>{{ editing ? 'Edit event' : 'Create event' }}</template>
        <template #content>
            <div class="flex flex-col gap-4">
                <Field>
                    <FieldLabel for="ce-title">Title</FieldLabel>
                    <TextInput id="ce-title" v-model="title" placeholder="Title" />
                </Field>
                <Field>
                    <FieldLabel for="ce-desc">Description</FieldLabel>
                    <TextareaInput id="ce-desc" v-model="description" :rows="3" placeholder="Optional" />
                </Field>
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="ce-event-all-day"
                        :checked="allDay"
                        @update:checked="(v: boolean | 'indeterminate') => setAllDay(v === true)" />
                    <label for="ce-event-all-day" class="cursor-pointer select-none text-sm text-text-primary">
                        All day
                    </label>
                </div>
                <template v-if="allDay">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field>
                            <FieldLabel for="ce-all-day-start">First day</FieldLabel>
                            <DatePicker
                                id="ce-all-day-start"
                                v-model="allDayStartAt"
                                class="w-full"
                                tabindex="1" />
                        </Field>
                        <Field>
                            <FieldLabel for="ce-all-day-end">Last day</FieldLabel>
                            <DatePicker
                                id="ce-all-day-end"
                                v-model="allDayEndAt"
                                class="w-full"
                                tabindex="1" />
                        </Field>
                    </div>
                </template>
                <template v-else>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field>
                            <FieldLabel>Start</FieldLabel>
                            <div class="flex flex-col gap-2">
                                <TimePickerSimple v-model="localStart" class="w-full" />
                                <DatePicker v-model="localStart" class="w-full" tabindex="1" />
                            </div>
                        </Field>
                        <Field>
                            <FieldLabel>End</FieldLabel>
                            <div class="flex flex-col gap-2">
                                <TimePickerSimple v-model="localEnd" class="w-full" />
                                <DatePicker v-model="localEnd" class="w-full" tabindex="1" />
                            </div>
                        </Field>
                    </div>
                </template>
                <Field>
                    <FieldLabel>Visibility</FieldLabel>
                    <Select v-model="visibility">
                        <SelectTrigger><SelectValue placeholder="Visibility" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="shared">Shared</SelectItem>
                            <SelectItem value="private">Private</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
                <template v-if="allowPickAttachment">
                    <div
                        class="overflow-hidden rounded-xl border border-border-secondary bg-card-background shadow-sm dark:shadow-none">
                        <div
                            class="border-b border-border-secondary bg-tertiary/20 px-4 py-3 dark:bg-secondary/20">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-text-primary">Link to</p>
                                    <p class="mt-0.5 text-xs leading-snug text-text-secondary">
                                        Three areas:
                                        <span class="text-violet-600/75 dark:text-violet-400/75">Workspace</span>,
                                        <span class="text-sky-700/75 dark:text-sky-400/75">Projects</span>, and
                                        <span class="text-emerald-700/75 dark:text-emerald-400/75">Tasks</span>.
                                        Combine them as needed.
                                    </p>
                                </div>
                                <SecondaryButton
                                    v-if="hasAnyLinkSelection"
                                    type="button"
                                    class="shrink-0 text-xs"
                                    @click="selectWorkspaceOnly">
                                    Clear links
                                </SecondaryButton>
                            </div>
                        </div>
                        <div class="space-y-4 p-4">
                            <div
                                v-if="hasStaleLinkSelections"
                                class="rounded-lg border border-amber-500/35 bg-amber-500/[0.06] p-3 dark:border-amber-400/30 dark:bg-amber-500/[0.08]">
                                <p class="text-xs font-medium text-text-primary">Previously linked items</p>
                                <p class="mt-0.5 text-[11px] leading-snug text-text-secondary">
                                    Archived or completed — they are not offered below. Tap to remove from
                                    this event.
                                </p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button
                                        v-for="p in selectedUnavailableProjects"
                                        :key="`stale-project-${p.id}`"
                                        type="button"
                                        :class="
                                            twMerge(
                                                projectChipButtonClass(true),
                                                'opacity-90 ring-1 ring-amber-500/40 dark:ring-amber-400/35'
                                            )
                                        "
                                        :style="projectChipSurface(p, true)"
                                        :aria-pressed="true"
                                        @click="toggleProject(p.id)">
                                        <span
                                            class="h-2.5 w-2.5 shrink-0 rounded-full ring-2 ring-white/80 dark:ring-black/20"
                                            :style="{ backgroundColor: p.color }" />
                                        <span class="min-w-0 truncate">{{ p.name }}</span>
                                        <span
                                            class="shrink-0 rounded bg-amber-500/25 px-1 text-[10px] font-semibold uppercase text-amber-900 dark:text-amber-100">
                                            Archived
                                        </span>
                                    </button>
                                    <button
                                        v-for="t in selectedUnavailableTasks"
                                        :key="`stale-task-${t.id}`"
                                        type="button"
                                        :class="
                                            twMerge(
                                                taskChipButtonClass(true),
                                                'opacity-90 ring-1 ring-amber-500/40 dark:ring-amber-400/35'
                                            )
                                        "
                                        :aria-pressed="true"
                                        @click="toggleTask(t.id)">
                                        <span
                                            class="h-2 w-2 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    projects.find((x) => x.id === t.project_id)?.color ??
                                                    '#6B7280',
                                            }" />
                                        <span class="min-w-0 truncate">{{ t.name }}</span>
                                        <span
                                            class="shrink-0 rounded bg-amber-500/25 px-1 text-[10px] font-semibold uppercase text-amber-900 dark:text-amber-100">
                                            {{
                                                tasks.find((x) => x.id === t.id)?.is_done
                                                    ? 'Done'
                                                    : 'Unavailable'
                                            }}
                                        </span>
                                    </button>
                                </div>
                            </div>
                            <section
                                class="rounded-lg border border-border-secondary border-l-[3px] border-l-violet-500/45 bg-violet-500/[0.03] p-3 dark:border-border-secondary dark:border-l-violet-400/40 dark:bg-violet-500/[0.045]">
                                <div class="mb-3 flex gap-2">
                                    <BuildingOffice2Icon
                                        class="mt-0.5 h-4 w-4 shrink-0 text-violet-600/85 dark:text-violet-400/85" />
                                    <div class="min-w-0">
                                        <p
                                            class="text-xs font-bold uppercase tracking-wide text-text-primary">
                                            Workspace
                                        </p>
                                        <p class="mt-0.5 text-[11px] leading-snug text-text-secondary">
                                            Whole organization — not tied to a specific project or task.
                                        </p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    :class="workspacePillClass(isWorkspaceOnlySelected)"
                                    :aria-pressed="isWorkspaceOnlySelected"
                                    data-testid="ce_link_workspace"
                                    @click="selectWorkspaceOnly">
                                    <BuildingOffice2Icon
                                        class="h-5 w-5 shrink-0"
                                        :class="NOTE_NOTABLE_LEVEL_PILL_STYLE.workspace.iconClass" />
                                    <span>Workspace only</span>
                                </button>
                            </section>

                            <section
                                class="rounded-lg border border-border-secondary border-l-[3px] border-l-sky-500/45 bg-sky-500/[0.03] p-3 dark:border-border-secondary dark:border-l-sky-400/40 dark:bg-sky-500/[0.045]">
                                <div class="mb-3 flex gap-2">
                                    <FolderIcon
                                        class="mt-0.5 h-4 w-4 shrink-0 text-sky-600/85 dark:text-sky-400/85" />
                                    <div class="min-w-0">
                                        <p
                                            class="text-xs font-bold uppercase tracking-wide text-text-primary">
                                            Projects
                                        </p>
                                        <p class="mt-0.5 text-[11px] leading-snug text-text-secondary">
                                            Pick any projects this event relates to. Project color shows on the
                                            calendar block.
                                        </p>
                                    </div>
                                </div>
                                <div
                                    v-if="projectsSorted.length === 0"
                                    class="text-xs italic text-text-tertiary">
                                    No projects available.
                                </div>
                                <div v-else class="flex flex-wrap gap-2">
                                    <button
                                        v-for="p in projectsSorted"
                                        :key="p.id"
                                        type="button"
                                        :class="
                                            projectChipButtonClass(selectedProjectIds.includes(p.id))
                                        "
                                        :style="projectChipSurface(p, selectedProjectIds.includes(p.id))"
                                        :aria-pressed="selectedProjectIds.includes(p.id)"
                                        :data-testid="`ce_link_project_${p.id}`"
                                        @click="toggleProject(p.id)">
                                        <span
                                            class="h-2.5 w-2.5 shrink-0 rounded-full ring-2 ring-white/80 dark:ring-black/20"
                                            :style="{ backgroundColor: p.color }" />
                                        <span class="min-w-0 truncate">{{ p.name }}</span>
                                    </button>
                                </div>
                            </section>

                            <section
                                class="rounded-lg border border-border-secondary border-l-[3px] border-l-emerald-500/45 bg-emerald-500/[0.03] p-3 dark:border-border-secondary dark:border-l-emerald-400/40 dark:bg-emerald-500/[0.045]">
                                <div class="mb-3 flex gap-2">
                                    <ListBulletIcon
                                        class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600/85 dark:text-emerald-400/85" />
                                    <div class="min-w-0">
                                        <p
                                            class="text-xs font-bold uppercase tracking-wide text-text-primary">
                                            Tasks
                                        </p>
                                        <p class="mt-0.5 text-[11px] leading-snug text-text-secondary">
                                            Tasks are grouped under their project. Dots match the project color.
                                        </p>
                                    </div>
                                </div>
                                <p
                                    v-if="tasksGroupedByProject.length === 0"
                                    class="text-xs italic text-text-tertiary">
                                    No tasks available.
                                </p>
                                <div
                                    v-else
                                    class="max-h-[min(14rem,calc(100vh-24rem))] space-y-3 overflow-y-auto overscroll-contain rounded-md border border-border-secondary/90 bg-tertiary/20 p-2 dark:border-border-secondary dark:bg-secondary/25">
                                    <div v-for="group in tasksGroupedByProject" :key="group.project.id">
                                        <p
                                            class="mb-1.5 truncate border-b border-border-secondary/60 pb-1 pl-0.5 text-[11px] font-semibold uppercase tracking-wide text-text-tertiary">
                                            {{ group.project.name }}
                                        </p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button
                                                v-for="t in group.tasks"
                                                :key="t.id"
                                                type="button"
                                                :class="
                                                    taskChipButtonClass(selectedTaskIds.includes(t.id))
                                                "
                                                :aria-pressed="selectedTaskIds.includes(t.id)"
                                                :data-testid="`ce_link_task_${t.id}`"
                                                @click="toggleTask(t.id)">
                                                <span
                                                    class="h-2 w-2 shrink-0 rounded-full"
                                                    :style="{ backgroundColor: group.project.color }" />
                                                <span class="min-w-0 truncate">{{ t.name }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </template>
            </div>
        </template>
        <template #footer>
            <SecondaryButton type="button" @click="show = false">Cancel</SecondaryButton>
            <PrimaryButton type="button" :disabled="submitBlocked || saving" @click="onSubmit">{{
                editing ? 'Save' : 'Create'
            }}</PrimaryButton>
        </template>
    </DialogModal>
</template>
