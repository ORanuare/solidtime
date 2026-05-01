<script setup lang="ts">
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import TextareaInput from '@/packages/ui/src/Input/TextareaInput.vue';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/packages/ui/src';
import Checkbox from '@/packages/ui/src/Input/Checkbox.vue';
import { computed, ref, watch } from 'vue';
import { getDayJsInstance, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import TimePickerSimple from '@/packages/ui/src/Input/TimePickerSimple.vue';
import DatePicker from '@/packages/ui/src/Input/DatePicker.vue';
import type {
    CreateOrgCalendarEventBody,
    OrgCalendarEvent,
    Project,
    Task,
} from '@/packages/api/src';

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

type AttachMode = 'workspace' | 'project' | 'task';
const attachMode = ref<AttachMode>('workspace');
const pickedProjectId = ref('');
const pickedTaskId = ref('');

function resetAttachPickers() {
    pickedProjectId.value = '';
    pickedTaskId.value = '';
}

function defaultAttachMode(): AttachMode {
    if (props.defaultTaskId && props.defaultProjectId) return 'task';
    if (props.defaultProjectId) return 'project';
    return 'workspace';
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
    if (ev.task_id) {
        attachMode.value = 'task';
        pickedTaskId.value = ev.task_id;
        pickedProjectId.value = ev.project_id || '';
    } else if (ev.project_id) {
        attachMode.value = 'project';
        pickedProjectId.value = ev.project_id;
        pickedTaskId.value = '';
    } else {
        attachMode.value = 'workspace';
        resetAttachPickers();
    }
}

function populateForCreate() {
    title.value = '';
    description.value = '';
    visibility.value = 'shared';
    allDay.value = props.initialAllDay ?? false;
    attachMode.value = props.allowPickAttachment ? defaultAttachMode() : defaultAttachMode();
    pickedProjectId.value = props.defaultProjectId ?? '';
    pickedTaskId.value = props.defaultTaskId ?? '';
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

const tasksSorted = computed(() =>
    [...props.tasks].sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }))
);

const projectsSorted = computed(() =>
    [...props.projects].sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }))
);

const tasksForPickedProject = computed(() => {
    if (!pickedProjectId.value) return tasksSorted.value;
    return tasksSorted.value.filter((t) => t.project_id === pickedProjectId.value);
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

function attachmentPayload(): { task_id?: string | null; project_id?: string | null } {
    if (!props.allowPickAttachment) {
        if (props.defaultTaskId && props.defaultProjectId) {
            return { task_id: props.defaultTaskId };
        }
        if (props.defaultProjectId) {
            return { project_id: props.defaultProjectId };
        }
        return {};
    }
    if (attachMode.value === 'task' && pickedTaskId.value) {
        return { task_id: pickedTaskId.value };
    }
    if (attachMode.value === 'project' && pickedProjectId.value) {
        return { project_id: pickedProjectId.value };
    }
    return {};
}

const submitBlocked = computed(() => !title.value.trim());

async function onSubmit() {
    const range = buildStartsEndsUtc();
    if (!range || submitBlocked.value) return;
    saving.value = true;
    try {
        const attach = attachmentPayload();
        if (props.editing) {
            const body = buildPartialUpdate(range);
            emit('save-update', { id: props.editing.id, body });
        } else {
            emit('save-create', {
                title: title.value.trim(),
                description: description.value.trim() || null,
                starts_at: range.starts_at,
                ends_at: range.ends_at,
                all_day: allDay.value,
                visibility: visibility.value,
                ...attach,
            });
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

    if (attachMode.value === 'task' && pickedTaskId.value) {
        return { ...base, reassign: true, task_id: pickedTaskId.value };
    }
    if (attachMode.value === 'project' && pickedProjectId.value) {
        return { ...base, reassign: true, project_id: pickedProjectId.value };
    }
    return { ...base, reassign: true, task_id: null, project_id: null };
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
                    <Field>
                        <FieldLabel>Attach to</FieldLabel>
                        <Select
                            :model-value="attachMode"
                            @update:model-value="
                                (v) => {
                                    attachMode = v as AttachMode;
                                    resetAttachPickers();
                                }
                            ">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="workspace">Workspace</SelectItem>
                                <SelectItem value="project">Project</SelectItem>
                                <SelectItem value="task">Task</SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field v-if="attachMode === 'project' || attachMode === 'task'">
                        <FieldLabel>Project</FieldLabel>
                        <Select v-model="pickedProjectId">
                            <SelectTrigger><SelectValue placeholder="Project" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="p in projectsSorted" :key="p.id" :value="p.id">{{
                                    p.name
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field v-if="attachMode === 'task'">
                        <FieldLabel>Task</FieldLabel>
                        <Select v-model="pickedTaskId" :disabled="!pickedProjectId">
                            <SelectTrigger><SelectValue placeholder="Task" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="t in tasksForPickedProject" :key="t.id" :value="t.id">{{
                                    t.name
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
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
