<script setup lang="ts">
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { PlusIcon, PencilSquareIcon, TrashIcon, ArchiveBoxIcon } from '@heroicons/vue/20/solid';
import { PlusIcon as PlusIconSm } from '@heroicons/vue/16/solid';
import { ClipboardDocumentListIcon } from '@heroicons/vue/24/solid';
import { useNotesQuery } from '@/utils/useNotesQuery';
import { computed, ref } from 'vue';
import NoteFormModal from '@/Components/Common/Note/NoteFormModal.vue';
import NoteMarkdownView from '@/Components/Common/Note/NoteMarkdownView.vue';
import { Badge } from '@/packages/ui/src';
import { canCreateNotes, canDeleteNotes, canUpdateNotes } from '@/utils/permissions';
import { useNotesStore } from '@/utils/useNotes';
import { getCurrentOrganizationId, getCurrentUserId } from '@/utils/useUser';
import type { Note } from '@/packages/api/src';
import { ChevronDownIcon, ChevronUpIcon } from '@heroicons/vue/20/solid';
import NoteTableHeading from '@/Components/Common/Note/NoteTableHeading.vue';
import TableRow from '@/Components/TableRow.vue';
import { formatDateTimeLocalized } from '@/packages/ui/src/utils/time';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';

const props = withDefaults(
    defineProps<{
        projectId?: string;
        taskId?: string;
        search?: string;
        visibilityFilter?: 'private' | 'shared' | '';
        /** Notes list `archived` query; omitted means server default (non-archived only). */
        archivedFilter?: 'true' | 'false' | 'all';
        /**
         * When true, show the "New note" bar above the list. Set false when the parent
         * places the action in a CardTitle or page header and calls `openCreate()` via ref.
         */
        showTopCreateAction?: boolean;
    }>(),
    { showTopCreateAction: true }
);

const listFilters = computed(() => ({
    projectId: props.projectId,
    taskId: props.taskId,
    search: props.search,
    visibility: props.visibilityFilter || undefined,
    archived: props.archivedFilter,
}));

const { notes, isLoading } = useNotesQuery(listFilters);
const { deleteNote, updateNote } = useNotesStore();
const showForm = ref(false);
const noteToEdit = ref<Note | null>(null);
const expandedId = ref<string | null>(null);

const currentUserId = getCurrentUserId();

const orgId = getCurrentOrganizationId() ?? '';
const { organization } = useOrganizationQuery(orgId);

function formatNoteTime(iso: string | null | undefined) {
    if (!iso) {
        return '—';
    }
    return formatDateTimeLocalized(
        iso,
        organization.value?.date_format,
        organization.value?.time_format
    );
}

function canEdit(note: Note) {
    return canUpdateNotes() && note.user_id === currentUserId;
}

function canDeleteNote(note: Note) {
    return canDeleteNotes() && note.user_id === currentUserId;
}

function openCreate() {
    noteToEdit.value = null;
    showForm.value = true;
}

function openEdit(note: Note) {
    noteToEdit.value = note;
    showForm.value = true;
}

function toggleExpand(id: string) {
    expandedId.value = expandedId.value === id ? null : id;
}

async function toggleArchive(note: Note) {
    await updateNote({
        noteId: note.id,
        body: { is_archived: !note.is_archived },
    });
}

defineExpose({ openCreate });
</script>

<template>
    <div>
        <div
            v-if="showTopCreateAction && canCreateNotes()"
            class="mb-4 flex w-full justify-end gap-2">
            <SecondaryButton :icon="PlusIcon" @click="openCreate()"> New note </SecondaryButton>
        </div>
        <p v-if="isLoading" class="text-sm text-text-secondary py-6 text-center">Loading…</p>
        <div v-else class="flow-root">
            <div class="inline-block min-w-full align-middle">
                <div
                    data-testid="note_list"
                    class="grid min-w-full gap-x-1"
                    role="table"
                    style="grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr) 110px minmax(0, 160px) minmax(120px, auto)">
                    <NoteTableHeading />
                    <div v-if="!notes.length" class="col-span-5 py-24 text-center">
                        <ClipboardDocumentListIcon
                            class="w-8 h-8 text-icon-default inline-block mb-2" />
                        <h3 class="text-text-primary font-semibold">No notes found</h3>
                        <p v-if="canCreateNotes()" class="text-text-secondary text-sm mt-1 pb-5">
                            {{
                                taskId
                                    ? 'Jot an idea for this task.'
                                    : projectId
                                      ? 'Create your first project note now!'
                                      : 'Capture ideas and todos in Markdown without leaving your workflow.'
                            }}
                        </p>
                        <p v-else class="text-text-secondary text-sm mt-1 pb-5">No notes to show yet.</p>
                        <SecondaryButton
                            v-if="canCreateNotes()"
                            :icon="PlusIconSm"
                            @click="openCreate()">
                            {{ taskId ? 'Add a note' : 'Create your first note' }}
                        </SecondaryButton>
                    </div>
                    <template v-else>
                        <template v-for="n in notes" :key="n.id">
                        <TableRow>
                            <div
                                class="min-w-0 pl-4 sm:pl-6 lg:pl-8 3xl:pl-12 pr-3 py-4 text-sm text-text-primary flex items-center">
                                <div class="min-w-0 w-full">
                                    <p class="font-medium leading-tight truncate" :title="n.title">
                                        {{ n.title }}
                                    </p>
                                    <p class="text-xs text-text-tertiary leading-tight mt-0.5 truncate">
                                        {{ n.user_name }}
                                    </p>
                                </div>
                            </div>
                            <div
                                class="min-w-0 px-3 py-4 text-sm text-text-primary flex items-center"
                                :title="n.notable_label">
                                <span class="truncate block">{{ n.notable_label }}</span>
                            </div>
                            <div
                                class="shrink-0 px-3 py-4 text-sm text-text-primary flex items-center justify-start flex-wrap gap-1">
                                <Badge v-if="n.visibility === 'private'" class="text-xs w-fit">Private</Badge>
                                <Badge v-else class="text-xs w-fit">Shared</Badge>
                                <Badge v-if="n.is_archived" class="text-xs w-fit text-text-tertiary"
                                    >Archived</Badge
                                >
                            </div>
                            <div
                                class="whitespace-nowrap pl-3 pr-2 sm:pr-3 py-4 text-sm text-text-secondary min-w-0 flex items-center">
                                <span class="block truncate" :title="formatNoteTime(n.updated_at || n.created_at)">
                                    {{ formatNoteTime(n.updated_at || n.created_at) }}
                                </span>
                            </div>
                            <div
                                class="relative flex items-center justify-end gap-0.5 pl-2 pr-3 sm:pr-6 lg:pr-8 py-4 shrink-0 min-w-0">
                                <button
                                    type="button"
                                    class="p-1.5 rounded text-text-secondary hover:bg-white/5"
                                    :aria-label="expandedId === n.id ? 'Collapse note' : 'Expand note'"
                                    @click="toggleExpand(n.id)">
                                    <ChevronUpIcon v-if="expandedId === n.id" class="w-5 h-5" />
                                    <ChevronDownIcon v-else class="w-5 h-5" />
                                </button>
                                <button
                                    v-if="canEdit(n)"
                                    type="button"
                                    class="p-1.5 rounded text-text-secondary hover:bg-white/5"
                                    :aria-label="n.is_archived ? 'Unarchive note' : 'Archive note'"
                                    @click="toggleArchive(n)">
                                    <ArchiveBoxIcon class="w-5 h-5" />
                                </button>
                                <button
                                    v-if="canEdit(n)"
                                    type="button"
                                    class="p-1.5 rounded text-text-secondary hover:bg-white/5"
                                    aria-label="Edit note"
                                    @click="openEdit(n)">
                                    <PencilSquareIcon class="w-5 h-5" />
                                </button>
                                <button
                                    v-if="canDeleteNote(n)"
                                    type="button"
                                    class="p-1.5 rounded text-destructive hover:bg-white/5"
                                    aria-label="Delete note"
                                    @click="deleteNote(n.id)">
                                    <TrashIcon class="w-5 h-5" />
                                </button>
                            </div>
                        </TableRow>
                        <div
                            v-if="expandedId === n.id"
                            class="col-span-5 col-start-1 border-b border-row-separator bg-row-background px-4 sm:px-6 lg:px-8 3xl:px-10 py-4 text-left">
                            <NoteMarkdownView :source="n.body" />
                        </div>
                        </template>
                    </template>
                </div>
            </div>
        </div>
        <NoteFormModal
            v-model:show="showForm"
            :project-id="projectId"
            :task-id="taskId"
            :note="noteToEdit" />
    </div>
</template>
