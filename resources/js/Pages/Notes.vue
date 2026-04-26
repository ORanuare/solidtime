<script setup lang="ts">
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ClipboardDocumentIcon, PlusIcon } from '@heroicons/vue/20/solid';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import { canCreateNotes, canViewNotes } from '@/utils/permissions';
import { ref, computed, watch } from 'vue';
import NoteList from '@/Components/Common/Note/NoteList.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';
import NoteFormModal from '@/Components/Common/Note/NoteFormModal.vue';
import { useDebounceFn } from '@vueuse/core';
import Card from '@/Components/Common/Card.vue';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';

const searchInput = ref('');
const debouncedSearch = ref('');

const runDebouncedSearch = useDebounceFn((v: string) => {
    debouncedSearch.value = v;
}, 300);

watch(
    searchInput,
    (v) => {
        runDebouncedSearch(v);
    },
    { immediate: true }
);

const visibilityFilter = ref<'private' | 'shared' | ''>('');
const archivedStatusFilter = ref<'false' | 'true' | 'all'>('false');
/** Match Projects list: hide notes for archived projects unless viewing Archived or All. */
const projectArchiveFilter = ref<'active' | 'archived' | 'all'>('active');

const listProps = computed(() => ({
    search: debouncedSearch.value.trim() || undefined,
    visibilityFilter: visibilityFilter.value,
    archivedFilter: archivedStatusFilter.value,
    workspaceProjectArchiveFilter: projectArchiveFilter.value,
}));

const showCreate = ref(false);

const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();
</script>

<template>
    <AppLayout title="Notes" data-testid="notes_view">
        <template v-if="canViewNotes()">
        <MainContainer
            class="py-5 border-b border-default-background-separator flex justify-between items-center flex-wrap gap-4">
            <PageTitle :icon="ClipboardDocumentIcon" title="Notes" />
            <SecondaryButton
                v-if="canCreateNotes()"
                :icon="PlusIcon"
                @click="showCreate = true"
                >New note
            </SecondaryButton>
        </MainContainer>
        <MainContainer class="pt-6">
            <FieldGroup class="mb-6 max-w-4xl">
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    <Field>
                        <FieldLabel for="noteSearch">Search</FieldLabel>
                        <TextInput
                            id="noteSearch"
                            v-model="searchInput"
                            type="search"
                            class="block w-full"
                            placeholder="Title or content…"
                            autocomplete="off" />
                    </Field>
                    <Field>
                        <FieldLabel for="noteVisibility">Visibility</FieldLabel>
                        <select
                            id="noteVisibility"
                            v-model="visibilityFilter"
                            class="block w-full rounded-md border border-default bg-card-background text-text-primary text-sm py-2 px-3 shadow-sm focus:ring-2 focus:ring-ring focus:border-transparent">
                            <option value="">All</option>
                            <option value="private">Private</option>
                            <option value="shared">Shared</option>
                        </select>
                    </Field>
                    <Field>
                        <FieldLabel for="noteStatus">Status</FieldLabel>
                        <select
                            id="noteStatus"
                            v-model="archivedStatusFilter"
                            class="block w-full rounded-md border border-default bg-card-background text-text-primary text-sm py-2 px-3 shadow-sm focus:ring-2 focus:ring-ring focus:border-transparent">
                            <option value="false">Active</option>
                            <option value="true">Archived</option>
                            <option value="all">All</option>
                        </select>
                    </Field>
                    <Field>
                        <FieldLabel for="noteProjectStatus">Project</FieldLabel>
                        <select
                            id="noteProjectStatus"
                            v-model="projectArchiveFilter"
                            class="block w-full rounded-md border border-default bg-card-background text-text-primary text-sm py-2 px-3 shadow-sm focus:ring-2 focus:ring-ring focus:border-transparent">
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                            <option value="all">All</option>
                        </select>
                    </Field>
                </div>
            </FieldGroup>
            <Card>
                <NoteList v-bind="listProps" :show-top-create-action="false" />
            </Card>
        </MainContainer>
        <NoteFormModal
            v-model:show="showCreate"
            allow-pick-any-attachment
            :projects
            :tasks />
        </template>
        <MainContainer v-else class="py-10">
            <p class="text-sm text-text-secondary">You do not have permission to view notes.</p>
        </MainContainer>
    </AppLayout>
</template>
