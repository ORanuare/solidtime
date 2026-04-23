<script setup lang="ts">
import type { Task } from '@/packages/api/src';
import { CheckCircleIcon } from '@heroicons/vue/20/solid';
import { useTasksStore } from '@/utils/useTasks';
import TaskMoreOptionsDropdown from '@/Components/Common/Task/TaskMoreOptionsDropdown.vue';
import TableRow from '@/Components/TableRow.vue';
import { canCreateTasks, canDeleteTasks, canUpdateTasks } from '@/utils/permissions';
import TaskEditModal from '@/Components/Common/Task/TaskEditModal.vue';
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

const showTaskActions = computed(
    () => canDeleteTasks() || canUpdateTasks() || canCreateTasks()
);
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
                @add-sub-task="emit('addSubTask', task.id)"></TaskMoreOptionsDropdown>
        </div>
        <TaskEditModal v-model:show="showTaskEditModal" :task="task"></TaskEditModal>
    </TableRow>
</template>

<style scoped></style>
