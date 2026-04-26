<script setup lang="ts">
import type { Task } from '@/packages/api/src';
import { CheckCircleIcon, ClipboardDocumentListIcon } from '@heroicons/vue/20/solid';
import { useTasksStore } from '@/utils/useTasks';
import TaskMoreOptionsDropdown from '@/Components/Common/Task/TaskMoreOptionsDropdown.vue';
import TableRow from '@/Components/TableRow.vue';
import { canCreateTasks, canDeleteTasks, canUpdateTasks, canViewNotes } from '@/utils/permissions';
import TaskEditModal from '@/Components/Common/Task/TaskEditModal.vue';
import TaskNotesModal from '@/Components/Common/Note/TaskNotesModal.vue';
import { computed, ref, inject, type ComputedRef } from 'vue';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import EstimatedTimeProgress from '@/packages/ui/src/EstimatedTimeProgress.vue';
import UpgradeBadge from '@/Components/Common/UpgradeBadge.vue';
import { formatHumanReadableDuration } from '../../../packages/ui/src/utils/time';
import type { Organization } from '@/packages/api/src';

const props = defineProps<{
    task: Task;
    depth: number;
}>();

const emit = defineEmits<{
    addSubTask: [parentId: string];
    filterNotesByTask: [task: Task];
}>();

const organization = inject<ComputedRef<Organization>>('organization');

function deleteTask() {
    useTasksStore().deleteTask(props.task.id);
}

function markTaskAsDone() {
    useTasksStore().updateTask(props.task.id, {
        ...props.task,
        is_done: !props.task.is_done,
    });
}

const showTaskEditModal = ref(false);
const showTaskNotesModal = ref(false);

const showTaskActions = computed(
    () => canDeleteTasks() || canUpdateTasks() || canCreateTasks() || canViewNotes()
);

const notesCount = computed(() => props.task.notes_count ?? 0);

function onNotesColumnActivate() {
    if (!canViewNotes()) {
        return;
    }
    emit('filterNotesByTask', props.task);
}
</script>

<template>
    <TableRow>
        <div
            class="whitespace-nowrap min-w-0 flex items-center space-x-5 3xl:pl-12 py-4 pr-3 text-sm font-medium text-text-primary pl-4 sm:pl-6 lg:pl-8 3xl:pl-12"
            :class="depth > 0 ? 'border-l-2 border-default-background-separator' : ''"
            :style="
                depth > 0
                    ? { marginLeft: `${Math.min(depth, 8) * 12 + 12}px` }
                    : undefined
            ">
            <span
                class="overflow-ellipsis overflow-hidden"
                :class="depth > 0 ? 'pl-2 text-text-secondary' : ''">
                {{ task.name }}
            </span>
        </div>
        <button
            v-if="canViewNotes()"
            type="button"
            class="group whitespace-nowrap px-1 py-4 flex items-center justify-center gap-1 rounded-md w-full h-full -my-1 text-left hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :title="'Filter project notes for this task'"
            :aria-label="
                notesCount > 0
                    ? `Filter notes by this task (${notesCount} ${notesCount === 1 ? 'note' : 'notes'})`
                    : 'Filter notes by this task (no notes yet)'
            "
            @click="onNotesColumnActivate">
            <span
                class="inline-flex items-center justify-center gap-1 transition-opacity"
                :class="
                    notesCount === 0
                        ? 'opacity-50 group-hover:opacity-100'
                        : ''
                ">
                <ClipboardDocumentListIcon
                    class="h-5 w-5 shrink-0 text-icon-default"
                    aria-hidden="true" />
                <span class="min-w-[1.25ch] text-center text-sm font-medium tabular-nums text-text-primary">
                    {{ notesCount }}
                </span>
            </span>
        </button>
        <div
            v-else
            class="whitespace-nowrap px-1 py-4 flex items-center justify-center gap-1"
            :aria-label="
                notesCount > 0
                    ? `${notesCount} ${notesCount === 1 ? 'note' : 'notes'} on this task`
                    : 'No notes on this task'
            ">
            <span
                class="inline-flex items-center justify-center gap-1"
                :class="notesCount === 0 ? 'opacity-50' : ''">
                <ClipboardDocumentListIcon
                    class="h-5 w-5 shrink-0 text-icon-default"
                    aria-hidden="true" />
                <span class="min-w-[1.25ch] text-center text-sm font-medium tabular-nums text-text-primary">
                    {{ notesCount }}
                </span>
            </span>
        </div>
        <div
            class="whitespace-nowrap px-3 py-4 text-sm text-text-secondary flex space-x-1 items-center font-medium">
            <span v-if="task.spent_time">
                {{
                    formatHumanReadableDuration(
                        task.spent_time,
                        organization?.interval_format,
                        organization?.number_format
                    )
                }}
            </span>
            <span v-else> -- </span>
        </div>
        <div class="whitespace-nowrap px-3 flex items-center text-sm text-text-secondary">
            <UpgradeBadge v-if="!isAllowedToPerformPremiumAction()"></UpgradeBadge>
            <EstimatedTimeProgress
                v-else-if="task.estimated_time"
                :estimated="task.estimated_time"
                :current="task.spent_time"></EstimatedTimeProgress>
            <span v-else> -- </span>
        </div>
        <div
            class="whitespace-nowrap px-3 py-4 text-sm text-text-secondary flex space-x-1 items-center font-medium">
            <template v-if="task.is_done">
                <CheckCircleIcon class="w-5"></CheckCircleIcon>
                <span>Done</span>
            </template>
            <template v-else>
                <span>Active</span>
            </template>
        </div>
        <div
            class="relative whitespace-nowrap flex items-center pl-3 text-right text-sm font-medium sm:pr-0 pr-4 sm:pr-6 lg:pr-8 3xl:pr-12">
            <TaskMoreOptionsDropdown
                v-if="showTaskActions"
                :task="task"
                @done="markTaskAsDone"
                @edit="showTaskEditModal = true"
                @delete="deleteTask"
                @notes="showTaskNotesModal = true"
                @add-sub-task="emit('addSubTask', task.id)"></TaskMoreOptionsDropdown>
        </div>
        <TaskEditModal v-model:show="showTaskEditModal" :task="task"></TaskEditModal>
        <TaskNotesModal v-model:show="showTaskNotesModal" :task="task" />
    </TableRow>
</template>

<style scoped></style>
