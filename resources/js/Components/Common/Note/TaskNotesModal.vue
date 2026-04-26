<script setup lang="ts">
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import type { Task } from '@/packages/api/src';
import NoteList from '@/Components/Common/Note/NoteList.vue';
import Card from '@/Components/Common/Card.vue';

const show = defineModel('show', { default: false });

defineProps<{
    task: Task;
}>();
</script>

<template>
    <DialogModal closeable :show="show" max-width="6xl" @close="show = false">
        <template #title>
            <span>Notes: {{ task.name }}</span>
        </template>
        <template #content>
            <Card class="p-3 sm:p-4 lg:p-5">
                <NoteList :task-id="task.id" />
            </Card>
        </template>
        <template #footer>
            <SecondaryButton @click="show = false">Close</SecondaryButton>
        </template>
    </DialogModal>
</template>
