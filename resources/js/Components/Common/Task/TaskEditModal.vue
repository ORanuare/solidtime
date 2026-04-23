<script setup lang="ts">
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { computed, ref, watch } from 'vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { useFocus } from '@vueuse/core';
import { useTasksStore } from '@/utils/useTasks';
import type { Task, UpdateTaskBody } from '@/packages/api/src';
import EstimatedTimeSection from '@/packages/ui/src/EstimatedTimeSection.vue';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';
import { useTasksQuery } from '@/utils/useTasksQuery';

const { updateTask } = useTasksStore();
const { tasks } = useTasksQuery();
const show = defineModel('show', { default: false });
const saving = ref(false);

const props = defineProps<{
    task: Task;
    hasChildren: boolean;
}>();

type TaskEditBody = Pick<UpdateTaskBody, 'name' | 'estimated_time' | 'parent_task_id'>;

const taskBody = ref<TaskEditBody>({
    name: props.task.name,
    estimated_time: props.task.estimated_time,
    parent_task_id: props.task.parent_task_id ?? null,
});

const rootTasksInProject = computed(() =>
    tasks.value.filter(
        (t) =>
            t.project_id === props.task.project_id &&
            !t.parent_task_id &&
            t.id !== props.task.id
    )
);

const parentTaskSelect = computed({
    get: () => taskBody.value.parent_task_id ?? '',
    set: (v: string) => {
        taskBody.value.parent_task_id = v === '' ? null : v;
    },
});

watch(show, (open) => {
    if (open) {
        taskBody.value = {
            name: props.task.name,
            estimated_time: props.task.estimated_time,
            parent_task_id: props.task.parent_task_id ?? null,
        };
    }
});

async function submit() {
    const body: UpdateTaskBody = {
        name: taskBody.value.name,
        estimated_time: taskBody.value.estimated_time,
    };
    if (!props.hasChildren) {
        body.parent_task_id = taskBody.value.parent_task_id;
    }
    await updateTask(props.task.id, body);
    show.value = false;
}

const taskNameInput = ref<HTMLInputElement | null>(null);

useFocus(taskNameInput, { initialValue: true });
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false">
        <template #title>
            <div class="flex space-x-2">
                <span> Update Task </span>
            </div>
        </template>

        <template #content>
            <FieldGroup>
                <Field>
                    <FieldLabel for="taskName">Task name</FieldLabel>
                    <TextInput
                        id="taskName"
                        ref="taskNameInput"
                        v-model="taskBody.name"
                        type="text"
                        placeholder="Task Name"
                        class="block w-full"
                        required
                        autocomplete="taskName"
                        @keydown.enter="submit()" />
                </Field>
                <Field v-if="!hasChildren">
                    <FieldLabel for="parentTask">Parent task</FieldLabel>
                    <select
                        id="parentTask"
                        v-model="parentTaskSelect"
                        class="block w-full rounded-md border border-default bg-card-background text-text-primary text-sm py-2 px-3 shadow-sm focus:ring-2 focus:ring-ring focus:border-transparent">
                        <option value="">None (top-level)</option>
                        <option v-for="r in rootTasksInProject" :key="r.id" :value="r.id">
                            {{ r.name }}
                        </option>
                    </select>
                </Field>
                <EstimatedTimeSection
                    v-if="isAllowedToPerformPremiumAction()"
                    v-model="taskBody.estimated_time"
                    @submit="submit()"></EstimatedTimeSection>
            </FieldGroup>
        </template>
        <template #footer>
            <SecondaryButton @click="show = false"> Cancel </SecondaryButton>
            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving"
                @click="submit">
                Update Task
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
