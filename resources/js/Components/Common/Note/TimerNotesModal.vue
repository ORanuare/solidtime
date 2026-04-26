<script setup lang="ts">
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import NoteList from '@/Components/Common/Note/NoteList.vue';
import NoteFormModal from '@/Components/Common/Note/NoteFormModal.vue';
import Card from '@/Components/Common/Card.vue';
import { computed, ref, watch } from 'vue';
import { canCreateNotes } from '@/utils/permissions';

const show = defineModel('show', { default: false });

const props = defineProps<{
    projectId?: string;
    taskId?: string;
    projectName?: string;
    taskName?: string;
}>();

function hasId(v: string | null | undefined): boolean {
    return v != null && v !== '';
}

const hasListScope = computed(() => hasId(props.projectId) || hasId(props.taskId));

/**
 * When the timer has a project, list by project so notes on the project or any task in it
 * (matching "Attach to" in the form) appear together. Task-only when there is no project.
 */
const listProjectId = computed(() => (hasId(props.projectId) ? props.projectId : undefined));
const listTaskId = computed(() =>
    hasId(props.projectId) ? undefined : hasId(props.taskId) ? props.taskId : undefined
);

const formProjectIdForTimer = computed(() => (hasId(props.projectId) ? props.projectId : undefined));
const formTaskIdForTimer = computed(() => (hasId(props.taskId) ? props.taskId : undefined));

const subtitle = computed(() => {
    const parts: string[] = [];
    if (props.projectName) {
        parts.push(props.projectName);
    }
    if (props.taskName) {
        parts.push(props.taskName);
    }
    return parts.length ? parts.join(' · ') : undefined;
});

const showWorkspaceNoteForm = ref(false);

function openWorkspaceNote() {
    showWorkspaceNoteForm.value = true;
}

watch(show, (open) => {
    if (!open) {
        showWorkspaceNoteForm.value = false;
    }
});
</script>

<template>
    <DialogModal closeable :show="show" max-width="6xl" @close="show = false">
        <template #title>
            <span class="flex flex-col gap-0.5">
                <span>Notes</span>
                <span v-if="subtitle" class="text-sm font-normal text-text-secondary">{{ subtitle }}</span>
            </span>
        </template>
        <template #content>
            <Card class="p-3 sm:p-4 lg:p-5">
                <template v-if="hasListScope">
                    <NoteList
                        :project-id="listProjectId"
                        :task-id="listTaskId"
                        :form-project-id="formProjectIdForTimer"
                        :form-task-id="formTaskIdForTimer"
                        :project-name="projectName"
                        :task-name="taskName" />
                </template>
                <div v-else class="py-10 text-center">
                    <p class="text-text-secondary text-sm max-w-md mx-auto">
                        Select a project or task on the timer to see notes for that work. You can still
                        capture a workspace note below.
                    </p>
                    <SecondaryButton
                        v-if="canCreateNotes()"
                        class="mt-6"
                        @click="openWorkspaceNote">
                        New workspace note
                    </SecondaryButton>
                </div>
            </Card>
        </template>
        <template #footer>
            <SecondaryButton @click="show = false">Close</SecondaryButton>
        </template>
    </DialogModal>
    <NoteFormModal
        v-if="showWorkspaceNoteForm"
        v-model:show="showWorkspaceNoteForm"
        :project-name="projectName"
        :task-name="taskName" />
</template>
