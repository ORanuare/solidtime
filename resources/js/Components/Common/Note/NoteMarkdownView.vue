<script setup lang="ts">
import { computed } from 'vue';
import { renderNoteMarkdownToHtml } from '@/utils/renderNoteMarkdown';

const props = defineProps<{
    source: string;
}>();

const html = computed(() => renderNoteMarkdownToHtml(props.source));
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -- output is DOMPurify.sanitize in script -->
    <div
        class="prose prose-sm dark:prose-invert max-w-none text-text-primary note-markdown"
        v-html="html" />
</template>

<style scoped>
.note-markdown :deep(a) {
    color: var(--color-text-link, var(--color-icon-active, #3b82f6));
    text-decoration: underline;
}
</style>
