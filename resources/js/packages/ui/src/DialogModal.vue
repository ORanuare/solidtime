<script setup lang="ts">
import Modal from './Modal.vue';
import DialogDescription from './dialog/DialogDescription.vue';
import DialogTitle from './dialog/DialogTitle.vue';

const emit = defineEmits(['close']);

defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const close = () => {
    emit('close');
};
</script>

<template>
    <Modal :show="show" :max-width="maxWidth" :closeable="closeable" @close="close">
        <div class="px-4 lg:px-6 py-4">
            <DialogTitle class="text-lg font-medium text-text-primary">
                <slot name="title" />
            </DialogTitle>
            <DialogDescription v-if="$slots.description" class="mt-1 text-sm text-text-secondary">
                <slot name="description" />
            </DialogDescription>
            <DialogDescription v-else class="sr-only">Dialog content and actions.</DialogDescription>

            <div class="mt-4 text-sm text-text-secondary">
                <slot name="content" />
            </div>
        </div>

        <div
            class="flex flex-row justify-end px-6 py-4 border-t border-card-background-separator bg-default-background rounded-b-2xl text-end">
            <slot name="footer" />
        </div>
    </Modal>
</template>
