<script setup lang="ts">
import type { Note } from '@/packages/api/src';
import NoteNotablePill from '@/Components/Common/Note/NoteNotablePill.vue';
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
        <div class="mt-0.5 min-w-0 max-w-full overflow-hidden">
            <NoteNotablePill v-if="note.notable_label" :note="note" size="sm" />
        </div>
    </div>
</template>
