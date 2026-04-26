<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useLocalStorage } from '@vueuse/core';
import { twMerge } from 'tailwind-merge';
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
import { isWorkspaceNote } from '@/utils/noteNotableLevel';
import {
    getNoteListFilterPillStyle,
    type TimerFocusNotesListMode,
} from '@/utils/noteNotablePillStyle';
import type { Note } from '@/packages/api/src';

const { hasListScope, listProjectId, listTaskId, formProjectId, formTaskId } = useTimerNoteScope();
const { createNote } = useNotesStore();

const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();

const listMode = useLocalStorage<TimerFocusNotesListMode>(
    'solidtime/timer-focus-notes-list-mode',
    'all'
);

/** Project for this timer, or the current task’s project when the entry has a task but no project. */
const resolvedProjectId = computed((): string | undefined => {
    if (listProjectId.value) {
        return listProjectId.value;
    }
    if (listTaskId.value) {
        return tasks.value.find((t) => t.id === listTaskId.value)?.project_id;
    }
    return undefined;
});

const listFilters = computed(() => {
    const base = { archived: 'false' as const };
    if (listMode.value === 'all' || listMode.value === 'workspace') {
        return base;
    }
    if (listMode.value === 'project') {
        const pid = resolvedProjectId.value;
        if (!pid) {
            return base;
        }
        return { ...base, projectId: pid };
    }
    if (listMode.value === 'task') {
        const tid = listTaskId.value;
        if (!tid) {
            return base;
        }
        return { ...base, taskId: tid };
    }
    return base;
});

const notesQueryEnabled = computed(() => {
    if (listMode.value === 'all' || listMode.value === 'workspace') {
        return true;
    }
    if (listMode.value === 'project') {
        return Boolean(resolvedProjectId.value);
    }
    if (listMode.value === 'task') {
        return Boolean(listTaskId.value);
    }
    return false;
});

const { notes, isLoading } = useNotesQuery(listFilters, {
    enabled: notesQueryEnabled,
});

watch(
    [() => listMode.value, resolvedProjectId, listTaskId],
    () => {
        if (listMode.value === 'project' && !resolvedProjectId.value) {
            listMode.value = 'all';
        }
        if (listMode.value === 'task' && !listTaskId.value) {
            listMode.value = 'all';
        }
    },
    { flush: 'post', immediate: true }
);

/** Hide notes tied to done tasks or archived projects (project-only API scope still returns all tasks). */
const visibleNotesForFocus = computed(() => {
    const allTasks = tasks.value;
    const allProjects = projects.value;
    let list = notes.value.filter((n: Note) => {
        if (n.task_id) {
            const task = allTasks.find((t) => t.id === n.task_id);
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
        if (n.project_id) {
            const p = allProjects.find((x) => x.id === n.project_id);
            if (p?.is_archived) {
                return false;
            }
        }
        return true;
    });
    if (listMode.value === 'workspace') {
        list = list.filter((n) => isWorkspaceNote(n));
    }
    return list;
});

/** Prefer project on the time entry, else project of the current task, for focus ordering. */
const sortProjectId = computed(
    () => formProjectId.value ?? resolvedProjectId.value
);

const sortedNotes = computed(() =>
    sortNotesForTimerFocus(visibleNotesForFocus.value, sortProjectId.value, formTaskId.value)
);

const noteCount = computed(() => visibleNotesForFocus.value.length);

const canFilterByProject = computed(() => Boolean(resolvedProjectId.value));
const canFilterByTask = computed(() => Boolean(listTaskId.value));

const pillButtonLayout =
    'inline-flex min-h-[2rem] shrink-0 items-center justify-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

function isListModePillDisabled(mode: TimerFocusNotesListMode) {
    return (
        (mode === 'project' && !canFilterByProject.value) ||
        (mode === 'task' && !canFilterByTask.value)
    );
}

function listModePillClass(mode: TimerFocusNotesListMode) {
    const s = getNoteListFilterPillStyle(mode);
    if (isListModePillDisabled(mode)) {
        return twMerge(pillButtonLayout, s.chipClass, 'cursor-not-allowed opacity-45');
    }
    if (listMode.value === mode) {
        return twMerge(pillButtonLayout, s.chipClass);
    }
    return twMerge(
        pillButtonLayout,
        'border-border-secondary bg-tertiary/40 text-text-secondary dark:bg-secondary/40'
    );
}

function listModePillIconClass(mode: TimerFocusNotesListMode) {
    const s = getNoteListFilterPillStyle(mode);
    if (isListModePillDisabled(mode)) {
        return twMerge('h-3.5 w-3.5 shrink-0', s.iconClass, 'opacity-35');
    }
    if (listMode.value === mode) {
        return twMerge('h-3.5 w-3.5 shrink-0', s.iconClass);
    }
    return twMerge('h-3.5 w-3.5 shrink-0', s.iconClass, 'opacity-80');
}

function setListMode(mode: 'workspace' | 'project' | 'task') {
    if (mode === 'project' && !canFilterByProject.value) {
        return;
    }
    if (mode === 'task' && !canFilterByTask.value) {
        return;
    }
    if (listMode.value === mode) {
        listMode.value = 'all';
        return;
    }
    listMode.value = mode;
}

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

watch([listProjectId, listTaskId, listMode], resetComposerDefaultForNewScope);

/**
 * When the note list is shown, open composer if empty, else list first. Without a list,
 * the composer is the main action.
 */
const showComposer = ref(false);
const composerInitDone = ref(false);

watch(
    [isLoading, noteCount, notesQueryEnabled],
    () => {
        if (composerInitDone.value) {
            if (
                notesQueryEnabled.value &&
                noteCount.value > 0 &&
                showComposer.value &&
                !composerBody.value.trim()
            ) {
                showComposer.value = false;
            }
            return;
        }
        if (notesQueryEnabled.value) {
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
        notesQueryEnabled.value &&
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
        <div class="shrink-0 space-y-2 border-b border-default px-3 py-2">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-text-primary">Notes</h2>
                <button
                    v-if="canCreateNotes() && !showComposer"
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-text-tertiary transition hover:bg-white/5 hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    data-testid="timer_focus_notes_expand_composer"
                    @click="openComposer">
                    <PlusIcon class="h-4 w-4 shrink-0" aria-hidden="true" />
                    Add
                </button>
            </div>
            <div
                class="flex flex-wrap items-center gap-1.5"
                role="group"
                aria-label="Filter notes by workspace, project, or task"
                data-testid="timer_focus_notes_list_mode">
                <button
                    type="button"
                    :class="listModePillClass('workspace')"
                    :aria-pressed="listMode === 'workspace'"
                    title="Workspace-only notes. Click again to show every note."
                    @click="setListMode('workspace')">
                    <component
                        :is="getNoteListFilterPillStyle('workspace').icon"
                        :class="listModePillIconClass('workspace')"
                        aria-hidden="true" />
                    <span>{{ getNoteListFilterPillStyle('workspace').shortLabel }}</span>
                </button>
                <button
                    type="button"
                    :class="listModePillClass('project')"
                    :aria-pressed="listMode === 'project'"
                    :disabled="!canFilterByProject"
                    :title="
                        canFilterByProject
                            ? 'Notes on this project. Click again to show every note.'
                            : 'Set a project on the timer'
                    "
                    @click="setListMode('project')">
                    <component
                        :is="getNoteListFilterPillStyle('project').icon"
                        :class="listModePillIconClass('project')"
                        aria-hidden="true" />
                    <span>{{ getNoteListFilterPillStyle('project').shortLabel }}</span>
                </button>
                <button
                    type="button"
                    :class="listModePillClass('task')"
                    :aria-pressed="listMode === 'task'"
                    :disabled="!canFilterByTask"
                    :title="
                        canFilterByTask
                            ? 'Notes on this task. Click again to show every note.'
                            : 'Set a task on the timer'
                    "
                    @click="setListMode('task')">
                    <component
                        :is="getNoteListFilterPillStyle('task').icon"
                        :class="listModePillIconClass('task')"
                        aria-hidden="true" />
                    <span>{{ getNoteListFilterPillStyle('task').shortLabel }}</span>
                </button>
            </div>
        </div>

        <div v-if="canCreateNotes() && showComposer" class="shrink-0 space-y-2 border-b border-default p-3">
            <p
                v-if="!hasListScope"
                class="mb-1 text-xs text-text-secondary">
                Set a project or task on the timer to attach new notes to that work. You can add a
                workspace note now.
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

        <div v-if="notesQueryEnabled" class="min-h-0 flex-1 overflow-y-auto p-3">
            <p v-if="isLoading" class="text-center text-sm text-text-secondary">Loading…</p>
            <p
                v-else-if="!sortedNotes.length"
                class="text-center text-sm text-text-secondary">
                <template v-if="canCreateNotes() && showComposer">
                    No notes yet. Use the composer above.
                </template>
                <template v-else-if="canCreateNotes() && !showComposer">
                    No notes yet. Use Add in the header to add one.
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
