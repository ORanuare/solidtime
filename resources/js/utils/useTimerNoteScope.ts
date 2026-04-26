import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';

function hasId(v: string | null | undefined): boolean {
    return v != null && v !== '';
}

/**
 * Project/task scope for notes attached to the current timer (same rules as TimerNotesModal).
 */
export function useTimerNoteScope() {
    const { currentTimeEntry } = storeToRefs(useCurrentTimeEntryStore());

    const projectId = computed(() => currentTimeEntry.value.project_id || undefined);
    const taskId = computed(() => currentTimeEntry.value.task_id || undefined);

    const hasListScope = computed(() => hasId(projectId.value) || hasId(taskId.value));

    /** When set, list notes in this project; tasks fall back to the current task’s project if the entry has no project. */
    const listProjectId = computed(() => (hasId(projectId.value) ? projectId.value : undefined));
    const listTaskId = computed(() => (hasId(taskId.value) ? taskId.value : undefined));

    const formProjectId = computed(() => (hasId(projectId.value) ? projectId.value : undefined));
    const formTaskId = computed(() => (hasId(taskId.value) ? taskId.value : undefined));

    return {
        currentTimeEntry,
        projectId,
        taskId,
        hasListScope,
        listProjectId,
        listTaskId,
        formProjectId,
        formTaskId,
    };
}
