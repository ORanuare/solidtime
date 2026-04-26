<script setup lang="ts">
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { PlusIcon } from '@heroicons/vue/20/solid';
import { PlusIcon as PlusIconSm } from '@heroicons/vue/16/solid';
import { ClipboardDocumentListIcon } from '@heroicons/vue/24/solid';
import { useNotesQuery } from '@/utils/useNotesQuery';
import { computed, ref } from 'vue';
import NoteFormModal from '@/Components/Common/Note/NoteFormModal.vue';
import FocusNoteCard from '@/Components/Common/Note/FocusNoteCard.vue';
import { canCreateNotes } from '@/utils/permissions';
import type { Task } from '@/packages/api/src';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { filterNotesByWorkspaceProjectArchive } from '@/utils/filterNotesByWorkspaceProjectArchive';

const props = withDefaults(
    defineProps<{
        projectId?: string;
        taskId?: string;
        search?: string;
        visibilityFilter?: 'private' | 'shared' | '';
        /** Notes list `archived` query; omitted means server default (non-archived only). */
        archivedFilter?: 'true' | 'false' | 'all';
        /**
         * When true, show the "New note" bar above the list. Set false when the parent
         * places the action in a CardTitle or page header and calls `openCreate()` via ref.
         */
        showTopCreateAction?: boolean;
        /**
         * Override project/task passed to create/edit `NoteFormModal` when list filters
         * differ (e.g. timer lists by task_id but the form should offer project + task).
         */
        formProjectId?: string;
        formTaskId?: string;
        /** Display names for `NoteFormModal` (e.g. time tracker context). */
        projectName?: string;
        taskName?: string;
        /**
         * When set (e.g. project detail page with Active/Done task tabs), only show notes for tasks
         * that match the tab: active → incomplete tasks; done → completed tasks. Project- and
         * workspace-scoped notes are always shown.
         */
        projectTasksTab?: 'active' | 'done';
        /**
         * When set (global Notes page), only show notes that match the linked project’s
         * archive state: `active` → workspace + non-archived projects; `archived` → only notes
         * on archived projects; `all` → no extra filter. Omit on project detail, modals, etc.
         */
        workspaceProjectArchiveFilter?: 'active' | 'archived' | 'all';
    }>(),
    { showTopCreateAction: true }
);

const formProjectIdEffective = computed(() => props.formProjectId ?? props.projectId);
const formTaskIdEffective = computed(() => props.formTaskId ?? props.taskId);

const { projects: projectsList } = useProjectsQuery();
const { tasks: tasksList } = useTasksQuery();

/** Resolve labels for `NoteFormModal` when a parent only passes IDs (e.g. project page by task). */
const displayProjectName = computed(
    () =>
        props.projectName ??
        (formProjectIdEffective.value
            ? projectsList.value.find((p) => p.id === formProjectIdEffective.value)?.name
            : undefined)
);
const displayTaskName = computed(
    () =>
        props.taskName ??
        (formTaskIdEffective.value
            ? tasksList.value.find((t) => t.id === formTaskIdEffective.value)?.name
            : undefined)
);

const tasksForCurrentProjectForm = computed(() => {
    const pid = formProjectIdEffective.value;
    if (!pid) {
        return [];
    }
    return tasksList.value
        .filter((t) => t.project_id === pid)
        .sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
});

const listFilters = computed(() => ({
    projectId: props.projectId,
    taskId: props.taskId,
    search: props.search,
    visibility: props.visibilityFilter || undefined,
    archived: props.archivedFilter,
}));

const { notes, isLoading } = useNotesQuery(listFilters);

function taskForNote(taskId: string | undefined, tasksById: Task[]): Task | undefined {
    if (!taskId) {
        return undefined;
    }
    return tasksById.find((t) => t.id === taskId);
}

/**
 * 1) Project detail: match Active/Done task tab for task-tied notes.
 * 2) Global Notes: match active vs archived project link (incl. task notes in that project).
 */
const displayNotes = computed(() => {
    let list = notes.value;
    const taskTab = props.projectTasksTab;
    if (taskTab !== undefined) {
        list = list.filter((n) => {
            if (!n.task_id) {
                return true;
            }
            const task = taskForNote(n.task_id, tasksList.value);
            if (!task) {
                return true;
            }
            return taskTab === 'active' ? !task.is_done : task.is_done;
        });
    }
    const archive = props.workspaceProjectArchiveFilter;
    if (archive !== undefined) {
        list = filterNotesByWorkspaceProjectArchive(list, archive, projectsList.value);
    }
    return list;
});

const showForm = ref(false);

function openCreate() {
    showForm.value = true;
}

defineExpose({ openCreate });
</script>

<template>
    <div>
        <div
            v-if="showTopCreateAction && canCreateNotes()"
            class="mb-4 flex w-full justify-end gap-2">
            <SecondaryButton :icon="PlusIcon" @click="openCreate()"> New note </SecondaryButton>
        </div>
        <p v-if="isLoading" class="text-sm text-text-secondary py-6 text-center">Loading…</p>
        <div v-else-if="!displayNotes.length" class="py-24 text-center" data-testid="note_list_empty">
            <ClipboardDocumentListIcon class="w-8 h-8 text-icon-default inline-block mb-2" />
            <h3 class="text-text-primary font-semibold">No notes found</h3>
            <p v-if="canCreateNotes()" class="text-text-secondary text-sm mt-1 pb-5">
                {{
                    taskId
                        ? 'Jot an idea for this task.'
                        : projectId
                          ? 'Create your first project note now!'
                          : 'Capture ideas and todos in Markdown without leaving your workflow.'
                }}
            </p>
            <p v-else class="text-text-secondary text-sm mt-1 pb-5">No notes to show yet.</p>
            <SecondaryButton
                v-if="canCreateNotes()"
                :icon="PlusIconSm"
                @click="openCreate()">
                {{ taskId ? 'Add a note' : 'Create your first note' }}
            </SecondaryButton>
        </div>
        <ul
            v-else
            data-testid="note_list"
            class="flex list-none flex-col gap-3">
            <li v-for="n in displayNotes" :key="n.id">
                <FocusNoteCard :note="n" />
            </li>
        </ul>
        <NoteFormModal
            v-if="showForm"
            v-model:show="showForm"
            :project-id="formProjectIdEffective"
            :task-id="formTaskIdEffective"
            :project-name="displayProjectName"
            :task-name="displayTaskName"
            :tasks="tasksForCurrentProjectForm" />
    </div>
</template>
