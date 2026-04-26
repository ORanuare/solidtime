<script setup lang="ts">
import type { Note, Task } from '@/packages/api/src';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { Popover, PopoverContent, PopoverTrigger } from '@/packages/ui/src';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { getNoteNotableEntityName, getNoteNotableLevel, type NoteNotableLevel } from '@/utils/noteNotableLevel';
import { useNotesStore } from '@/utils/useNotes';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { BuildingOffice2Icon, FolderIcon, ListBulletIcon } from '@heroicons/vue/16/solid';
import { computed, ref, watch } from 'vue';
import { twMerge } from 'tailwind-merge';

const props = withDefaults(
    defineProps<{
        note: Note;
        class?: string;
        /** When false, only the level chip (icon + level name) is shown. */
        showEntityName?: boolean;
        size?: 'sm' | 'md';
        /**
         * When true, the level chip is a control that opens a popover to reassign
         * the note (requires note author; enforce in the parent with permissions).
         */
        reassignable?: boolean;
    }>(),
    {
        class: undefined,
        showEntityName: true,
        size: 'md',
        reassignable: false,
    }
);

const open = ref(false);
const { updateNote } = useNotesStore();
const { projects: projectsList } = useProjectsQuery();
const { tasks: tasksList } = useTasksQuery();
const saving = ref(false);

const level = computed(() => getNoteNotableLevel(props.note));
const entityName = computed(() => getNoteNotableEntityName(props.note));

const attachTo = ref<'task' | 'project' | 'workspace'>('workspace');
const pickedProjectId = ref('');
const pickedTaskId = ref('');

const projectNameById = computed(() => {
    const m = new Map<string, string>();
    for (const p of projectsList.value) {
        m.set(p.id, p.name);
    }
    return m;
});

const projectsSortedForSelect = computed(() =>
    [...projectsList.value].sort((a, b) =>
        a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })
    )
);

function taskOptionLabel(t: Task) {
    const pn = projectNameById.value.get(t.project_id);
    return pn ? `${t.name} — ${pn}` : t.name;
}

const tasksSortedForSelect = computed(() => {
    return [...tasksList.value].sort((a, b) => {
        const an = taskOptionLabel(a);
        const bn = taskOptionLabel(b);
        return an.localeCompare(bn, undefined, { sensitivity: 'base' });
    });
});

function syncAttachFromNote() {
    const lev = getNoteNotableLevel(props.note);
    if (lev === 'workspace') {
        attachTo.value = 'workspace';
        pickedProjectId.value = '';
        pickedTaskId.value = '';
    } else if (lev === 'project') {
        attachTo.value = 'project';
        pickedProjectId.value = props.note.project_id;
        pickedTaskId.value = '';
    } else {
        attachTo.value = 'task';
        pickedTaskId.value = props.note.task_id;
        pickedProjectId.value = props.note.project_id;
    }
}

watch(
    () => open.value,
    (v) => {
        if (v) {
            syncAttachFromNote();
        }
    }
);

watch(attachTo, (v) => {
    if (v !== 'project') {
        pickedProjectId.value = '';
    }
    if (v !== 'task') {
        pickedTaskId.value = '';
    }
});

const submitDisabled = computed(() => {
    if (attachTo.value === 'project' && !pickedProjectId.value) {
        return true;
    }
    if (attachTo.value === 'task' && !pickedTaskId.value) {
        return true;
    }
    return false;
});

async function onSaveReassign() {
    if (submitDisabled.value || saving.value) {
        return;
    }
    saving.value = true;
    try {
        if (attachTo.value === 'workspace') {
            await updateNote({ noteId: props.note.id, body: { reassign: true } });
        } else if (attachTo.value === 'project') {
            await updateNote({
                noteId: props.note.id,
                body: { reassign: true, project_id: pickedProjectId.value },
            });
        } else {
            await updateNote({
                noteId: props.note.id,
                body: { reassign: true, task_id: pickedTaskId.value },
            });
        }
        open.value = false;
    } finally {
        saving.value = false;
    }
}

const levelVisual: Record<
    NoteNotableLevel,
    { icon: typeof BuildingOffice2Icon; shortLabel: string; chipClass: string; iconClass: string }
> = {
    workspace: {
        icon: BuildingOffice2Icon,
        shortLabel: 'Workspace',
        chipClass:
            'border-violet-500/35 bg-violet-500/10 text-violet-800 dark:border-violet-400/30 dark:bg-violet-500/15 dark:text-violet-100',
        iconClass: 'text-violet-600 dark:text-violet-300',
    },
    project: {
        icon: FolderIcon,
        shortLabel: 'Project',
        chipClass:
            'border-sky-500/40 bg-sky-500/10 text-sky-900 dark:border-sky-400/35 dark:bg-sky-500/15 dark:text-sky-100',
        iconClass: 'text-sky-600 dark:text-sky-300',
    },
    task: {
        icon: ListBulletIcon,
        shortLabel: 'Task',
        chipClass:
            'border-emerald-500/40 bg-emerald-500/10 text-emerald-900 dark:border-emerald-400/35 dark:bg-emerald-500/15 dark:text-emerald-100',
        iconClass: 'text-emerald-600 dark:text-emerald-300',
    },
};

const levelStyle = computed(() => levelVisual[level.value]);

const sizeClasses = computed(() => {
    if (props.size === 'sm') {
        return {
            chip: 'gap-0.5 px-1.5 py-px text-[10px] leading-4',
            icon: 'h-3 w-3',
        };
    }
    return {
        chip: 'gap-1 px-2 py-0.5 text-xs',
        icon: 'h-3.5 w-3.5',
    };
});

const triggerChipClass = computed(() =>
    twMerge(
        'inline-flex shrink-0 items-center rounded-full border font-medium transition',
        sizeClasses.value.chip,
        levelStyle.value.chipClass,
        props.reassignable &&
            'cursor-pointer hover:brightness-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:hover:brightness-110'
    )
);
</script>

<template>
    <span :class="twMerge('inline-flex min-w-0 max-w-full items-center gap-1.5', props.class)">
        <Popover v-if="reassignable" v-model:open="open">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    :class="triggerChipClass"
                    :title="note.notable_label"
                    aria-label="Change where this note is attached"
                    :disabled="saving">
                    <component
                        :is="levelStyle.icon"
                        :class="twMerge(sizeClasses.icon, 'shrink-0', levelStyle.iconClass)" />
                    <span class="whitespace-nowrap">{{ levelStyle.shortLabel }}</span>
                </button>
            </PopoverTrigger>
            <PopoverContent class="w-80 p-4" align="start">
                <p class="text-sm font-medium text-text-primary">Attach note to</p>
                <p class="mt-1 text-xs text-text-tertiary">Workspace notes are not linked to a project or task.</p>
                <div class="mt-3 space-y-3">
                    <Field>
                        <FieldLabel for="notePillAttachTo">Target</FieldLabel>
                        <select
                            id="notePillAttachTo"
                            v-model="attachTo"
                            class="block w-full rounded-md border border-default bg-card-background px-3 py-2 text-sm text-text-primary shadow-sm focus:border-transparent focus:ring-2 focus:ring-ring">
                            <option value="workspace">Workspace</option>
                            <option value="project">Project</option>
                            <option value="task">Task</option>
                        </select>
                    </Field>
                    <Field v-if="attachTo === 'project'">
                        <FieldLabel for="notePillProject">Project</FieldLabel>
                        <select
                            id="notePillProject"
                            v-model="pickedProjectId"
                            class="block w-full rounded-md border border-default bg-card-background px-3 py-2 text-sm text-text-primary shadow-sm focus:border-transparent focus:ring-2 focus:ring-ring"
                            required>
                            <option disabled value="">Select a project</option>
                            <option v-for="p in projectsSortedForSelect" :key="p.id" :value="p.id">
                                {{ p.name }}
                            </option>
                        </select>
                    </Field>
                    <Field v-if="attachTo === 'task'">
                        <FieldLabel for="notePillTask">Task</FieldLabel>
                        <select
                            id="notePillTask"
                            v-model="pickedTaskId"
                            class="block w-full rounded-md border border-default bg-card-background px-3 py-2 text-sm text-text-primary shadow-sm focus:border-transparent focus:ring-2 focus:ring-ring"
                            required>
                            <option disabled value="">Select a task</option>
                            <option v-for="t in tasksSortedForSelect" :key="t.id" :value="t.id">
                                {{ taskOptionLabel(t) }}
                            </option>
                        </select>
                    </Field>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm text-text-secondary hover:bg-white/5"
                        @click="open = false">
                        Cancel
                    </button>
                    <PrimaryButton type="button" :disabled="submitDisabled || saving" @click="onSaveReassign()">
                        {{ saving ? 'Saving…' : 'Save' }}
                    </PrimaryButton>
                </div>
            </PopoverContent>
        </Popover>
        <span
            v-else
            :class="
                twMerge(
                    'inline-flex shrink-0 items-center rounded-full border font-medium',
                    sizeClasses.chip,
                    levelStyle.chipClass
                )
            "
            :title="note.notable_label">
            <component
                :is="levelStyle.icon"
                :class="twMerge(sizeClasses.icon, 'shrink-0', levelStyle.iconClass)" />
            <span class="whitespace-nowrap">{{ levelStyle.shortLabel }}</span>
        </span>
        <span
            v-if="showEntityName && entityName"
            class="min-w-0 truncate text-text-tertiary"
            :title="entityName">
            {{ entityName }}
        </span>
    </span>
</template>
