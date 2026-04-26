<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import NoteMarkdownEditor from '@/Components/Common/Note/NoteMarkdownEditor.vue';
import NoteMarkdownView from '@/Components/Common/Note/NoteMarkdownView.vue';
import { getNotePreviewLine } from '@/utils/notePreview';
import type { Note } from '@/packages/api/src';
import { useNotesStore } from '@/utils/useNotes';
import { canDeleteNotes, canUpdateNotes } from '@/utils/permissions';
import { getCurrentUserId } from '@/utils/useUser';
import {
    ArchiveBoxIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    TrashIcon,
} from '@heroicons/vue/20/solid';
import NoteNotablePill from '@/Components/Common/Note/NoteNotablePill.vue';

const props = defineProps<{
    note: Note;
}>();

const { updateNote, deleteNote } = useNotesStore();
const currentUserId = getCurrentUserId();

const localBody = ref(props.note.body);
const expanded = ref(false);
const isEditingBody = ref(false);
const saveStatus = ref<'idle' | 'saving' | 'saved'>('idle');
const bodyPreviewRef = ref<HTMLElement | null>(null);
const bodyEditorRef = ref<{ focus: () => void } | null>(null);
let savedClearTimer: ReturnType<typeof setTimeout> | null = null;

watch(
    () => props.note.body,
    (b) => {
        localBody.value = b;
    }
);

const canEdit = computed(() => canUpdateNotes() && props.note.user_id === currentUserId);
const canDelete = computed(() => canDeleteNotes() && props.note.user_id === currentUserId);

const bodyPreviewEmpty = computed(() => !localBody.value.trim());

let saveDebounceTimer: ReturnType<typeof setTimeout> | null = null;

watch(localBody, (v) => {
    if (!canEdit.value) {
        return;
    }
    if (saveDebounceTimer !== null) {
        clearTimeout(saveDebounceTimer);
    }
    saveDebounceTimer = setTimeout(async () => {
        saveDebounceTimer = null;
        if (v === props.note.body) {
            return;
        }
        saveStatus.value = 'saving';
        try {
            await updateNote({
                noteId: props.note.id,
                body: { body: v },
            });
            saveStatus.value = 'saved';
            if (savedClearTimer !== null) {
                clearTimeout(savedClearTimer);
            }
            savedClearTimer = setTimeout(() => {
                saveStatus.value = 'idle';
            }, 2000);
        } catch {
            saveStatus.value = 'idle';
        }
    }, 800);
});

onBeforeUnmount(() => {
    if (saveDebounceTimer !== null) {
        clearTimeout(saveDebounceTimer);
    }
    if (savedClearTimer !== null) {
        clearTimeout(savedClearTimer);
    }
});

function onEditorFocusOut(ev: FocusEvent) {
    const el = ev.currentTarget as HTMLElement;
    const next = ev.relatedTarget as Node | null;
    if (next && el.contains(next)) {
        return;
    }
    isEditingBody.value = false;
}

function onEditorEscape(e: KeyboardEvent) {
    e.preventDefault();
    e.stopPropagation();
    isEditingBody.value = false;
    void nextTick(() => {
        bodyPreviewRef.value?.focus();
    });
}

function enterEditFromPreview(e: { target: EventTarget | null }) {
    const t = e.target as HTMLElement;
    if (t.closest('a[href]')) {
        return;
    }
    isEditingBody.value = true;
    void nextTick(() => {
        bodyEditorRef.value?.focus();
    });
}

function onPreviewClick(e: MouseEvent) {
    enterEditFromPreview(e);
}

function onPreviewKeydown(e: KeyboardEvent) {
    if (e.key !== 'Enter' && e.key !== ' ') {
        return;
    }
    const t = e.target as HTMLElement;
    if (t.closest('a[href]')) {
        return;
    }
    e.preventDefault();
    enterEditFromPreview(e);
}

async function onArchive() {
    await updateNote({
        noteId: props.note.id,
        body: { is_archived: !props.note.is_archived },
    });
}

function onDelete() {
    void deleteNote(props.note.id);
}
</script>

<template>
    <div
        class="rounded-lg border border-default bg-card-background p-3 shadow-sm"
        :data-note-id="note.id">
        <div class="flex items-center justify-between gap-3">
            <div
                class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1 text-xs leading-5 text-text-tertiary">
                <span class="min-w-0 truncate font-medium text-text-secondary">
                    {{ note.user_name }}
                </span>
                <span
                    class="hidden h-3.5 w-px shrink-0 bg-default-background-separator sm:block"
                    aria-hidden="true" />
                <NoteNotablePill
                    v-if="note.notable_label"
                    :note="note"
                    class="min-w-0"
                    :reassignable="canEdit" />
                <span v-if="note.visibility === 'private'" class="shrink-0 whitespace-nowrap">
                    · Private
                </span>
                <span v-else class="shrink-0 whitespace-nowrap">· Shared</span>
                <span v-if="note.is_archived" class="shrink-0 whitespace-nowrap">· Archived</span>
            </div>
            <div class="flex shrink-0 items-center gap-0.5">
                <span
                    v-if="canEdit && saveStatus !== 'idle'"
                    class="text-[10px] uppercase tracking-wide text-text-tertiary">
                    {{ saveStatus === 'saving' ? 'Saving…' : 'Saved' }}
                </span>
                <button
                    v-if="canEdit"
                    type="button"
                    class="p-1 rounded text-text-secondary hover:bg-white/5"
                    :aria-label="note.is_archived ? 'Unarchive note' : 'Archive note'"
                    @click="onArchive">
                    <ArchiveBoxIcon class="h-4 w-4" />
                </button>
                <button
                    v-else
                    type="button"
                    class="p-1 rounded text-text-secondary hover:bg-white/5"
                    :aria-expanded="expanded"
                    :aria-label="expanded ? 'Collapse note' : 'Expand note'"
                    @click="expanded = !expanded">
                    <ChevronUpIcon v-if="expanded" class="h-4 w-4" />
                    <ChevronDownIcon v-else class="h-4 w-4" />
                </button>
                <button
                    v-if="canDelete"
                    type="button"
                    class="p-1 rounded text-destructive hover:bg-white/5"
                    aria-label="Delete note"
                    @click="onDelete">
                    <TrashIcon class="h-4 w-4" />
                </button>
            </div>
        </div>

        <div v-if="canEdit && !isEditingBody" class="mt-2">
            <div
                ref="bodyPreviewRef"
                class="w-full cursor-pointer select-text rounded-md border border-transparent p-0.5 text-left text-sm text-text-primary transition-colors hover:border-default hover:bg-white/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                role="button"
                tabindex="0"
                :aria-label="bodyPreviewEmpty ? 'Write markdown (open editor)' : 'Edit note (open editor)'"
                data-testid="focus_note_body_preview"
                @click="onPreviewClick"
                @keydown="onPreviewKeydown">
                <p v-if="bodyPreviewEmpty" class="py-1 text-left text-text-tertiary">Click to write markdown…</p>
                <NoteMarkdownView v-else :source="localBody" />
            </div>
        </div>
        <div
            v-else-if="canEdit"
            class="mt-2"
            @focusout="onEditorFocusOut"
            @keydown.esc.capture="onEditorEscape">
            <NoteMarkdownEditor
                ref="bodyEditorRef"
                v-model="localBody"
                placeholder-text="Write markdown…" />
        </div>
        <div v-else class="mt-2">
            <p class="text-sm text-text-primary line-clamp-2">
                {{ getNotePreviewLine(note.body) }}
            </p>
            <div v-if="expanded" class="mt-2 border-t border-default pt-2">
                <NoteMarkdownView class="text-sm" :source="note.body" />
            </div>
        </div>
    </div>
</template>
