<script setup lang="ts">
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { PlusCircleIcon } from '@heroicons/vue/24/solid';
import { PlusIcon } from '@heroicons/vue/16/solid';
import { computed, ref, watch } from 'vue';
import TaskTableRow from '@/Components/Common/Task/TaskTableRow.vue';
import TaskTableHeading from '@/Components/Common/Task/TaskTableHeading.vue';
import TaskCreateModal from '@/Components/Common/Task/TaskCreateModal.vue';
import { canCreateTasks } from '@/utils/permissions';
import type { Task } from '@/packages/api/src';
import { orderTasksWithSubTasks } from '@/utils/taskHierarchy';

const props = defineProps<{
    projectId: string;
    tasks: Task[];
}>();

const createTask = ref(false);
const createTaskParentId = ref<string | null>(null);

const orderedRows = computed(() => orderTasksWithSubTasks(props.tasks));

function openCreateRoot() {
    createTaskParentId.value = null;
    createTask.value = true;
}

function openCreateSubTask(parentId: string) {
    createTaskParentId.value = parentId;
    createTask.value = true;
}

watch(createTask, (show) => {
    if (!show) {
        createTaskParentId.value = null;
    }
});
</script>

<template>
    <TaskCreateModal
        v-model:show="createTask"
        :project-id="props.projectId"
        :parent-task-id="createTaskParentId"></TaskCreateModal>
    <div class="flow-root">
        <div class="inline-block min-w-full align-middle">
            <div
                data-testid="task_table"
                role="table"
                class="grid min-w-full"
                style="
                    grid-template-columns:
                        1fr minmax(4rem, auto) minmax(80px, auto) minmax(120px, auto)
                        minmax(50px, auto) 80px;
                ">
                <TaskTableHeading></TaskTableHeading>
                <div v-if="tasks.length === 0" class="col-span-6 py-24 text-center">
                    <PlusCircleIcon class="w-8 text-icon-default inline pb-2"></PlusCircleIcon>
                    <h3 class="text-text-primary font-semibold">No tasks found</h3>
                    <p v-if="canCreateTasks()" class="pb-5">Create your first task now!</p>
                    <SecondaryButton
                        v-if="canCreateTasks()"
                        :icon="PlusIcon"
                        @click="openCreateRoot"
                        >Create your First Task
                    </SecondaryButton>
                </div>
                <template v-for="{ task, depth } in orderedRows" :key="task.id">
                    <TaskTableRow
                        :task="task"
                        :depth="depth"
                        @add-sub-task="openCreateSubTask"></TaskTableRow>
                </template>
            </div>
        </div>
    </div>
</template>
