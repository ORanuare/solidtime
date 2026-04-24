<script setup lang="ts">
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/packages/ui/src/tabs';
import { useNotesStore } from '@/utils/useNotes';
import type { Note, Project, Task } from '@/packages/api/src';
import { computed, nextTick, ref, watch } from 'vue';
import NoteMarkdownEditor from '@/Components/Common/Note/NoteMarkdownEditor.vue';
import NoteMarkdownView from '@/Components/Common/Note/NoteMarkdownView.vue';

const show = defineModel('show', { default: false });

const props = withDefaults(
    defineProps<{
        projectId?: string;
        taskId?: string;
        /** Display name for the selected project (e.g. from time tracker). */
        projectName?: string;
        /** Display name for the selected task (e.g. from time tracker). */
        taskName?: string;
        note?: Note | null;
        /**
         * When true, show the compact attach selector to pick workspace, any project, or any task
         * (e.g. Notes page). Incompatible with passing fixed projectId/taskId for create.
         */
        allowPickAnyAttachment?: boolean;
        projects?: Project[];
        tasks?: Task[];
    }>(),
    {
        allowPickAnyAttachment: false,
        projects: () => [],
        tasks: () => [],
    }
);

const { createNote, updateNote } = useNotesStore();
const saving = ref(false);
const title = ref('');
const body = ref('');
const visibility = ref<'private' | 'shared'>('private');

/** Where a new note is stored (create only). */
const attachTo = ref<'task' | 'project' | 'workspace'>('workspace');

/** Picked project/task when `allowPickAnyAttachment` (IDs from lists, not from timer). */
const pickedProjectId = ref('');
const pickedTaskId = ref('');

const titleInput = ref<HTMLInputElement | null>(null);

const noteContentTab = ref<'write' | 'preview'>('write');
const bodyIsEmpty = computed(() => !body.value.trim());

function resetForCreate() {
    title.value = '';
    body.value = '';
    visibility.value = 'private';
}

function defaultAttachTarget(): 'task' | 'project' | 'workspace' {
    if (props.allowPickAnyAttachment) {
        return 'workspace';
    }
    if (props.taskId && props.projectId) {
        return 'task';
    }
    if (props.taskId) {
        return 'task';
    }
    if (props.projectId) {
        return 'project';
    }

    return 'workspace';
}

function resetPickedAttachment() {
    pickedProjectId.value = '';
    pickedTaskId.value = '';
}

/** Time tracker: choose between current task, current project, or workspace. */
const showContextAttachSelect = computed(
    () =>
        !props.allowPickAnyAttachment && !props.note && Boolean(props.taskId) && Boolean(props.projectId)
);

/** General notes: choose workspace, then pick a project or task from lists. */
const showFreeAttachment = computed(
    () => !props.note && Boolean(props.allowPickAnyAttachment)
);

const projectNameById = computed(() => {
    const m = new Map<string, string>();
    for (const p of props.projects) {
        m.set(p.id, p.name);
    }
    return m;
});

function taskOptionLabel(t: Task) {
    const pn = projectNameById.value.get(t.project_id);
    return pn ? `${t.name} — ${pn}` : t.name;
}

const projectsSortedForSelect = computed(() =>
    [...props.projects].sort((a, b) =>
        a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })
    )
);

const tasksSortedForSelect = computed(() => {
    return [...props.tasks].sort((a, b) => {
        const an = taskOptionLabel(a);
        const bn = taskOptionLabel(b);
        return an.localeCompare(bn, undefined, { sensitivity: 'base' });
    });
});

const createSubmitBlocked = computed(() => {
    if (props.note) {
        return false;
    }
    if (!props.allowPickAnyAttachment) {
        return false;
    }
    if (attachTo.value === 'project' && !pickedProjectId.value) {
        return true;
    }
    if (attachTo.value === 'task' && !pickedTaskId.value) {
        return true;
    }

    return false;
});

watch(attachTo, (v) => {
    if (v !== 'project') {
        pickedProjectId.value = '';
    }
    if (v !== 'task') {
        pickedTaskId.value = '';
    }
});

watch(
    show,
    (open) => {
        if (open) {
            noteContentTab.value = 'write';
            if (props.note) {
                title.value = props.note.title;
                body.value = props.note.body;
                visibility.value = props.note.visibility as 'private' | 'shared';
            } else {
                resetForCreate();
                resetPickedAttachment();
                attachTo.value = defaultAttachTarget();
            }
            nextTick(() => {
                titleInput.value?.focus();
            });
        }
    },
    { immediate: true }
);

async function submit() {
    if (!body.value.trim()) {
        return;
    }
    saving.value = true;
    try {
        if (props.note) {
            await updateNote({
                noteId: props.note.id,
                body: {
                    title: title.value,
                    body: body.value,
                    visibility: visibility.value,
                },
            });
        } else {
            if (props.allowPickAnyAttachment) {
                if (createSubmitBlocked.value) {
                    return;
                }
                if (attachTo.value === 'project') {
                    await createNote({
                        title: title.value,
                        body: body.value,
                        visibility: visibility.value,
                        project_id: pickedProjectId.value,
                    });
                } else if (attachTo.value === 'task') {
                    await createNote({
                        title: title.value,
                        body: body.value,
                        visibility: visibility.value,
                        task_id: pickedTaskId.value,
                    });
                } else {
                    await createNote({
                        title: title.value,
                        body: body.value,
                        visibility: visibility.value,
                    });
                }
            } else {
                const useTask = attachTo.value === 'task' && props.taskId;
                const useProject = attachTo.value === 'project' && props.projectId;

                if (useTask) {
                    await createNote({
                        title: title.value,
                        body: body.value,
                        visibility: visibility.value,
                        task_id: props.taskId,
                    });
                } else if (useProject) {
                    await createNote({
                        title: title.value,
                        body: body.value,
                        visibility: visibility.value,
                        project_id: props.projectId,
                    });
                } else {
                    await createNote({
                        title: title.value,
                        body: body.value,
                        visibility: visibility.value,
                    });
                }
            }
        }
        show.value = false;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <DialogModal closeable :show="show" max-width="2xl" @close="show = false">
        <template #title>
            {{ note ? 'Edit note' : 'New note' }}
        </template>
        <template #content>
            <FieldGroup>
                <template v-if="showFreeAttachment">
                    <Field>
                        <FieldLabel for="noteFreeAttachType">Attach to</FieldLabel>
                        <select
                            id="noteFreeAttachType"
                            v-model="attachTo"
                            class="block w-full rounded-md border border-default bg-card-background text-text-primary text-sm py-2 px-3 shadow-sm focus:ring-2 focus:ring-ring focus:border-transparent">
                            <option value="workspace">Workspace (not linked to a project or task)</option>
                            <option value="project">Project</option>
                            <option value="task">Task</option>
                        </select>
                    </Field>
                    <Field v-if="attachTo === 'project'">
                        <FieldLabel for="noteFreeProject">Project</FieldLabel>
                        <select
                            id="noteFreeProject"
                            v-model="pickedProjectId"
                            class="block w-full rounded-md border border-default bg-card-background text-text-primary text-sm py-2 px-3 shadow-sm focus:ring-2 focus:ring-ring focus:border-transparent"
                            required>
                            <option disabled value="">Select a project</option>
                            <option v-for="p in projectsSortedForSelect" :key="p.id" :value="p.id">
                                {{ p.name }}
                            </option>
                        </select>
                    </Field>
                    <Field v-if="attachTo === 'task'">
                        <FieldLabel for="noteFreeTask">Task</FieldLabel>
                        <select
                            id="noteFreeTask"
                            v-model="pickedTaskId"
                            class="block w-full rounded-md border border-default bg-card-background text-text-primary text-sm py-2 px-3 shadow-sm focus:ring-2 focus:ring-ring focus:border-transparent"
                            required>
                            <option disabled value="">Select a task</option>
                            <option v-for="t in tasksSortedForSelect" :key="t.id" :value="t.id">
                                {{ taskOptionLabel(t) }}
                            </option>
                        </select>
                    </Field>
                </template>
                <Field v-else-if="!note && showContextAttachSelect">
                    <FieldLabel for="noteAttachTo">Attach to</FieldLabel>
                    <select
                        id="noteAttachTo"
                        v-model="attachTo"
                        class="block w-full rounded-md border border-default bg-card-background text-text-primary text-sm py-2 px-3 shadow-sm focus:ring-2 focus:ring-ring focus:border-transparent">
                        <option value="task">Task — {{ taskName || 'current task' }}</option>
                        <option value="project">Project — {{ projectName || 'current project' }}</option>
                        <option value="workspace">Workspace (not linked to a project or task)</option>
                    </select>
                </Field>
                <p
                    v-else-if="!note && !allowPickAnyAttachment && taskId && !projectId"
                    class="text-sm text-text-secondary -mb-1">
                    <span class="text-text-tertiary">Attached to </span>
                    <span class="font-medium text-text-primary">Task: {{ taskName || 'selected task' }}</span>
                </p>
                <p
                    v-else-if="!note && !allowPickAnyAttachment && projectId && !taskId"
                    class="text-sm text-text-secondary -mb-1">
                    <span class="text-text-tertiary">Attached to </span>
                    <span class="font-medium text-text-primary">Project: {{ projectName || 'selected project' }}</span>
                </p>
                <Field>
                    <FieldLabel for="noteTitle">Title</FieldLabel>
                    <TextInput
                        id="noteTitle"
                        ref="titleInput"
                        v-model="title"
                        type="text"
                        class="block w-full"
                        required
                        autocomplete="off" />
                </Field>
                <Field>
                    <FieldLabel for="noteBody" class="mb-2">Content (Markdown)</FieldLabel>
                    <Tabs v-model="noteContentTab" class="w-full">
                        <TabsList class="flex w-full max-w-sm gap-0.5 sm:gap-1">
                            <TabsTrigger
                                value="write"
                                class="flex-1 rounded-md border border-tab-border py-1.5 text-xs font-medium text-text-tertiary data-[state=active]:border-input-border data-[state=active]:bg-tab-background data-[state=active]:text-text-primary sm:text-sm">
                                Write
                            </TabsTrigger>
                            <TabsTrigger
                                value="preview"
                                class="flex-1 rounded-md border border-tab-border py-1.5 text-xs font-medium text-text-tertiary data-[state=active]:border-input-border data-[state=active]:bg-tab-background data-[state=active]:text-text-primary sm:text-sm">
                                Preview
                            </TabsTrigger>
                        </TabsList>
                        <TabsContent value="write" class="mt-2 p-0">
                            <NoteMarkdownEditor v-model="body" data-testid="note-body-editor" />
                        </TabsContent>
                        <TabsContent value="preview" class="mt-2">
                            <div
                                class="min-h-60 max-h-80 overflow-y-auto rounded-md border border-default bg-card-background p-3">
                                <NoteMarkdownView v-if="body.trim().length" :source="body" />
                                <p v-else class="text-sm text-text-tertiary">Nothing to preview yet.</p>
                            </div>
                        </TabsContent>
                    </Tabs>
                </Field>
                <Field>
                    <FieldLabel for="noteVisibility">Visibility</FieldLabel>
                    <select
                        id="noteVisibility"
                        v-model="visibility"
                        class="block w-full rounded-md border border-default bg-card-background text-text-primary text-sm py-2 px-3 shadow-sm focus:ring-2 focus:ring-ring focus:border-transparent">
                        <option value="private">Private (only you)</option>
                        <option value="shared">Shared (team)</option>
                    </select>
                </Field>
            </FieldGroup>
        </template>
        <template #footer>
            <SecondaryButton :disabled="saving" @click="show = false">Cancel</SecondaryButton>
            <PrimaryButton
                class="ms-2"
                :disabled="saving || createSubmitBlocked || bodyIsEmpty"
                @click="submit()">
                {{ note ? 'Save' : 'Create' }}
            </PrimaryButton>
        </template>
    </DialogModal>
</template>
