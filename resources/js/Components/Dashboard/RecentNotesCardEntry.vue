<script setup lang="ts">
import type { Note } from '@/packages/api/src';
import { getNotePreviewLine } from '@/utils/notePreview';

defineProps<{
    note: Note;
}>();
</script>

<template>
    <div class="px-3.5 py-2 2xl:py-3 border-b border-border-tertiary last:border-b-0">
        <p class="text-sm font-medium min-w-0 text-ellipsis overflow-hidden text-text-primary">
            {{ getNotePreviewLine(note.body) }}
        </p>
        <p
            class="text-xs text-text-tertiary text-ellipsis whitespace-nowrap max-w-full overflow-hidden mt-0.5">
            {{ note.notable_label }}
        </p>
    </div>
</template>
