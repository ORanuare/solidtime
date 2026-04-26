<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { PlusIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import NoteMarkdownEditor from '@/Components/Common/Note/NoteMarkdownEditor.vue';
import FocusNoteCard from '@/Components/Common/Note/FocusNoteCard.vue';
import { useTimerNoteScope } from '@/utils/useTimerNoteScope';
import { useNotesQuery } from '@/utils/useNotesQuery';
import { useNotesStore } from '@/utils/useNotes';
import { canCreateNotes } from '@/utils/permissions';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { sortNotesForTimerFocus } from '@/utils/timerFocusNoteSort';

const { hasListScope, listProjectId, listTaskId, formProjectId, formTaskId } = useTimerNoteScope();
const { createNote } = useNotesStore();

const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();

const listFilters = computed(() => ({
    projectId: listProjectId.value,
    taskId: listTaskId.value,
    archived: 'false' as const,
}));

const { notes, isLoading } = useNotesQuery(listFilters, {
    enabled: hasListScope,
});

const sortedNotes = computed(() =>
    sortNotesForTimerFocus(notes.value, formProjectId.value, formTaskId.value)
);

const noteCount = computed(() => notes.length);

const projectName = computed(() => {
    const id = formProjectId.value;
    if (!id) {
        return undefined;
    }

    return projects.value.find((p) => p.id === id)?.name;
});

const taskName = computed(() => {
    const id = formTaskId.value;
    if (!id) {
        return undefined;
    }

    return tasks.value.find((t) => t.id === id)?.name;
});

const attachSelectTaskLabel = computed(() => (taskName.value ? `Task — ${taskName.value}` : 'Task'));
const attachSelectProjectLabel = computed(() =>
    projectName.value ? `Project — ${projectName.value}` : 'Project'
);

function defaultAttachTarget(): 'task' | 'project' | 'workspace' {
    if (formTaskId.value && formProjectId.value) {
        return 'task';
    }
    if (formTaskId.value) {
        return 'task';
    }
    if (formProjectId.value) {
        return 'project';
    }

    return 'workspace';
}

const attachTo = ref<'task' | 'project' | 'workspace'>(defaultAttachTarget());
const composerBody = ref('');
const composerVisibility = ref<'private' | 'shared'>('private');
const creating = ref(false);

const showContextAttachSelect = computed(
    () => Boolean(formTaskId.value) && Boolean(formProjectId.value)
);

watch([formProjectId, formTaskId], () => {
    attachTo.value = defaultAttachTarget();
});

/** List scope (project/task) changed — re-run default: list-first when notes exist. */
function resetComposerDefaultForNewScope() {
    composerInitDone.value = false;
    showComposer.value = false;
}

watch([listProjectId, listTaskId], resetComposerDefaultForNewScope);

/**
 * When there is a project/task, wait for the note list, then: open composer if empty,
 * else show list first. Without scope, the composer is the main action.
 *
 * Start with the composer closed so we do not show the form before the list loads, and
 * do not "lock" the wrong state if the first run saw `!hasListScope` then scope appears.
 */
const showComposer = ref(false);
const composerInitDone = ref(false);

watch(
    [isLoading, noteCount, hasListScope],
    () => {
        if (composerInitDone.value) {
            if (
                hasListScope.value &&
                noteCount.value > 0 &&
                showComposer.value &&
                !composerBody.value.trim()
            ) {
                showComposer.value = false;
            }
            return;
        }
        if (hasListScope.value) {
            if (isLoading.value) {
                return;
            }
            composerInitDone.value = true;
            showComposer.value = noteCount.value === 0;
        } else {
            composerInitDone.value = true;
            showComposer.value = true;
        }
    },
    { flush: 'post', immediate: true }
);

/** Stale empty result then cache fills (0 → n): show list, not the create form. */
watch(noteCount, (n, previous) => {
    if (previous === undefined) {
        return;
    }
    if (
        previous === 0 &&
        n > 0 &&
        hasListScope.value &&
        showComposer.value &&
        !composerBody.value.trim()
    ) {
        showComposer.value = false;
    }
});

const composerEmpty = computed(() => !composerBody.value.trim());

async function addNote() {
    if (composerEmpty.value || !canCreateNotes() || creating.value) {
        return;
    }
    creating.value = true;
    try {
        const body = composerBody.value.trim();
        const visibility = composerVisibility.value;
        const useTask = attachTo.value === 'task' && formTaskId.value;
        const useProject = attachTo.value === 'project' && formProjectId.value;
        if (useTask) {
            await createNote({
                body,
                visibility,
                task_id: formTaskId.value!,
            });
        } else if (useProject) {
            await createNote({
                body,
                visibility,
                project_id: formProjectId.value!,
            });
        } else {
            await createNote({ body, visibility });
        }
        composerBody.value = '';
        showComposer.value = false;
    } finally {
        creating.value = false;
    }
}

function openComposer() {
    showComposer.value = true;
}

function hideComposer() {
    showComposer.value = false;
    composerBody.value = '';
    composerVisibility.value = 'private';
    attachTo.value = defaultAttachTarget();
}
</script>

<template>
    <div
        class="flex h-full min-h-0 flex-col bg-default-background"
        data-testid="timer_focus_notes">
        <div class="shrink-0 border-b border-default px-3 py-2">
            <h2 class="text-sm font-semibold text-text-primary">Notes</h2>
        </div>

        <div v-if="canCreateNotes()">
            <div
                v-if="!showComposer"
                class="shrink-0 border-b border-default p-2">
                <SecondaryButton
                    :icon="PlusIcon"
                    class="w-full justify-center"
                    data-testid="timer_focus_notes_expand_composer"
                    @click="openComposer">
                    New note
                </SecondaryButton>
            </div>
            <div v-else class="shrink-0 space-y-2 border-b border-default p-3">
                <p
                    v-if="!hasListScope"
                    class="mb-1 text-xs text-text-secondary">
                    Select a project or task on the timer to list notes for that work. You can still add a
                    workspace note below.
                </p>
                <div v-if="showContextAttachSelect" class="mb-2">
                    <label for="focusNoteAttach" class="mb-1 block text-xs text-text-tertiary"
                        >Attach to</label
                    >
                    <select
                        id="focusNoteAttach"
                        v-model="attachTo"
                        class="block w-full rounded-md border border-default bg-card-background py-1.5 px-2 text-sm text-text-primary shadow-sm focus:ring-2 focus:ring-ring">
                        <option value="task">{{ attachSelectTaskLabel }}</option>
                        <option value="project">{{ attachSelectProjectLabel }}</option>
                        <option value="workspace">Workspace</option>
                    </select>
                </div>
                <NoteMarkdownEditor
                    v-model="composerBody"
                    data-testid="timer_focus_notes_composer"
                    placeholder-text="Jot a note…" />
                <div class="mt-2 flex flex-wrap items-stretch justify-end gap-2 sm:items-center">
                    <label for="focusNoteVis" class="sr-only">Visibility</label>
                    <select
                        id="focusNoteVis"
                        v-model="composerVisibility"
                        class="min-w-[7rem] flex-1 rounded-md border border-default bg-card-background py-1.5 px-2 text-sm text-text-primary sm:max-w-[10rem]">
                        <option value="private">Private</option>
                        <option value="shared">Shared</option>
                    </select>
                    <SecondaryButton
                        :icon="XMarkIcon"
                        type="button"
                        class="shrink-0"
                        data-testid="timer_focus_notes_hide_composer"
                        @click="hideComposer">
                        Close
                    </SecondaryButton>
                    <PrimaryButton
                        class="shrink-0"
                        :disabled="composerEmpty || creating"
                        @click="addNote">
                        Add note
                    </PrimaryButton>
                </div>
            </div>
        </div>

        <div v-if="hasListScope" class="min-h-0 flex-1 overflow-y-auto p-3">
            <p v-if="isLoading" class="text-center text-sm text-text-secondary">Loading…</p>
            <p
                v-else-if="!notes.length"
                class="text-center text-sm text-text-secondary">
                <template v-if="canCreateNotes() && showComposer">
                    No notes yet. Use the composer above.
                </template>
                <template v-else-if="canCreateNotes() && !showComposer">
                    No notes yet. Use New note above to add one.
                </template>
                <template v-else>No notes yet.</template>
            </p>
            <ul v-else class="flex list-none flex-col gap-3">
                <li v-for="n in sortedNotes" :key="n.id">
                    <FocusNoteCard :note="n" />
                </li>
            </ul>
        </div>
    </div>
</template>
