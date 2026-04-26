<script setup lang="ts">
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { FolderIcon, PlusIcon } from '@heroicons/vue/20/solid';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { computed, ref } from 'vue';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import {
    ChevronRightIcon,
    CheckCircleIcon,
    PencilSquareIcon,
    ClipboardDocumentIcon,
    UserGroupIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';

import { Link } from '@inertiajs/vue3';
import TaskCreateModal from '@/Components/Common/Task/TaskCreateModal.vue';
import TaskTable from '@/Components/Common/Task/TaskTable.vue';
import CardTitle from '@/packages/ui/src/CardTitle.vue';
import Card from '@/Components/Common/Card.vue';
import ProjectMemberTable from '@/Components/Common/ProjectMember/ProjectMemberTable.vue';
import ProjectMemberCreateModal from '@/Components/Common/ProjectMember/ProjectMemberCreateModal.vue';
import { useProjectMembersQuery } from '@/utils/useProjectMembersQuery';
import {
    canCreateProjects,
    canCreateTasks,
    canCreateNotes,
    canViewNotes,
    canViewProjectMembers,
} from '@/utils/permissions';
import NoteList from '@/Components/Common/Note/NoteList.vue';
import { TabBar, TabBarItem } from '@/packages/ui/src';
import { useTasksQuery } from '@/utils/useTasksQuery';
import ProjectEditModal from '@/Components/Common/Project/ProjectEditModal.vue';
import { Badge } from '@/packages/ui/src';
import { formatCents } from '../packages/ui/src/utils/money';
import { getOrganizationCurrencyString } from '../utils/money';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { getCurrentOrganizationId } from '@/utils/useUser';
import type { Task } from '@/packages/api/src';

const { projects } = useProjectsQuery();

const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);

const project = computed(() => {
    return projects.value.find((project) => project.id === route().params.project) ?? null;
});
const createTask = ref(false);
const createProjectMember = ref(false);
const projectId = route()?.params?.project as string;

const { projectMembers } = canViewProjectMembers()
    ? useProjectMembersQuery(projectId)
    : { projectMembers: computed(() => []) };

const activeTab = ref<'active' | 'done'>('active');
const { tasks } = useTasksQuery();

const showEditProjectModal = ref(false);
const projectNotesListRef = ref<{ openCreate: () => void } | null>(null);
const notesFilterTaskId = ref<string | null>(null);

const notesFilterTaskName = computed(() => {
    if (!notesFilterTaskId.value) {
        return '';
    }
    return tasks.value.find((t) => t.id === notesFilterTaskId.value)?.name ?? '';
});

/**
 * When the task tab is "Active", don't apply a filter to a completed task (notes for that task
 * only while viewing "Done" tasks). When switching to Done, the same selection applies again.
 */
const effectiveNotesFilterTaskId = computed(() => {
    if (!notesFilterTaskId.value) {
        return undefined;
    }
    const t = tasks.value.find((x) => x.id === notesFilterTaskId.value);
    if (!t) {
        return undefined;
    }
    if (t.is_done && activeTab.value === 'active') {
        return undefined;
    }
    return notesFilterTaskId.value;
});

function onFilterNotesByTask(task: Task) {
    if (notesFilterTaskId.value === task.id) {
        notesFilterTaskId.value = null;
    } else {
        notesFilterTaskId.value = task.id;
    }
}

function clearNotesTaskFilter() {
    notesFilterTaskId.value = null;
}

const billableRateFormatted = computed(() => {
    if (project.value?.billable_rate) {
        return formatCents(
            project.value.billable_rate,
            getOrganizationCurrencyString(),
            organization.value?.currency_format,
            organization.value?.currency_symbol,
            organization.value?.number_format
        );
    }
    return null;
});

const organizationDefaultBillableRateFormatted = computed(() => {
    if (organization.value?.billable_rate == null) {
        return null;
    }
    return formatCents(
        organization.value.billable_rate,
        getOrganizationCurrencyString(),
        organization.value?.currency_format,
        organization.value?.currency_symbol,
        organization.value?.number_format
    );
});

const fixedPriceFormatted = computed(() => {
    if (project.value?.billing_type === 'fixed' && project.value.fixed_price != null) {
        return formatCents(
            project.value.fixed_price,
            getOrganizationCurrencyString(),
            organization.value?.currency_format,
            organization.value?.currency_symbol,
            organization.value?.number_format
        );
    }
    return null;
});

const shownTasks = computed(() => {
    return tasks.value.filter((task) => {
        if (activeTab.value === 'active') {
            return task.project_id === projectId && !task.is_done;
        }
        return task.project_id === projectId && task.is_done;
    });
});
</script>

<template>
    <AppLayout title="Projects" data-testid="projects_view">
        <MainContainer
            class="py-5 border-b border-default-background-separator flex justify-between items-center">
            <nav class="flex" aria-label="Breadcrumb">
                <ol role="list" class="flex items-center space-x-2">
                    <li>
                        <div class="flex items-center space-x-6">
                            <Link
                                :href="route('projects')"
                                class="flex items-center space-x-2 sm:space-x-2.5">
                                <FolderIcon class="w-5 text-icon-default"></FolderIcon>
                                <span class="text-sm sm:text-base font-medium">Projects</span>
                            </Link>
                        </div>
                    </li>
                    <li>
                        <div
                            class="flex items-center space-x-3 text-text-primary font-semibold text-base">
                            <ChevronRightIcon
                                class="h-5 w-5 flex-shrink-0 text-text-secondary"
                                aria-hidden="true" />
                            <div class="flex space-x-3 items-center">
                                <div
                                    :style="{
                                        backgroundColor: project?.color,
                                        boxShadow: `var(--tw-ring-inset) 0 0 0 calc(4px + var(--tw-ring-offset-width)) ${project?.color}30`,
                                    }"
                                    class="w-3 h-3 rounded-full"></div>
                                <span>{{ project?.name }}</span>
                            </div>
                        </div>
                    </li>
                </ol>
                <div class="px-4">
                    <Badge v-if="fixedPriceFormatted">
                        {{ fixedPriceFormatted }}
                    </Badge>
                    <Badge v-else-if="project?.billable_rate">
                        {{ billableRateFormatted }}
                        / h
                    </Badge>
                    <Badge v-else-if="project?.is_billable && project?.billing_type !== 'fixed'">
                        <template v-if="organizationDefaultBillableRateFormatted">
                            {{ organizationDefaultBillableRateFormatted }}
                            / h
                        </template>
                        <span v-else>—</span>
                    </Badge>
                    <Badge v-else-if="project?.is_billable && project?.billing_type === 'fixed'">
                        Fixed
                    </Badge>
                    <Badge v-if="!project?.is_billable"> Non-Billable </Badge>
                    <Badge v-if="project && !project.is_paid"> Unpaid </Badge>
                </div>
            </nav>
            <div>
                <SecondaryButton
                    v-if="canCreateProjects()"
                    :icon="PencilSquareIcon"
                    @click="showEditProjectModal = true">
                    Edit Project
                </SecondaryButton>
                <ProjectEditModal
                    v-if="project"
                    v-model:show="showEditProjectModal"
                    :original-project="project"></ProjectEditModal>
            </div>
        </MainContainer>
        <MainContainer>
            <div class="pt-6">
                <CardTitle title="Tasks" :icon="CheckCircleIcon">
                    <template #actions>
                        <div class="w-full items-center flex justify-between">
                            <div class="pl-6">
                                <TabBar v-model="activeTab">
                                    <TabBarItem value="active">Active </TabBarItem>
                                    <TabBarItem value="done">Done </TabBarItem>
                                </TabBar>
                            </div>
                            <SecondaryButton
                                v-if="canCreateTasks()"
                                :icon="PlusIcon"
                                @click="createTask = true"
                                >Create Task
                            </SecondaryButton>
                            <TaskCreateModal
                                v-model:show="createTask"
                                :project-id="projectId"></TaskCreateModal>
                        </div>
                    </template>
                </CardTitle>
                <Card>
                    <TaskTable
                        :tasks="shownTasks"
                        :project-id="projectId"
                        @filter-notes-by-task="onFilterNotesByTask" />
                </Card>
            </div>
        </MainContainer>
        <MainContainer v-if="canViewNotes()" class="pt-6" :class="canViewProjectMembers() ? '' : 'pb-8'">
            <CardTitle title="Notes" :icon="ClipboardDocumentIcon">
                <template #actions>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <div
                            v-if="effectiveNotesFilterTaskId"
                            class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-border-secondary bg-tertiary pl-2.5 pr-1 py-1 text-sm text-text-primary dark:bg-secondary">
                            <span class="text-text-tertiary shrink-0">Task</span>
                            <span class="min-w-0 truncate font-medium" :title="notesFilterTaskName">
                                {{ notesFilterTaskName || '…' }}
                            </span>
                            <button
                                type="button"
                                class="shrink-0 rounded p-1 text-text-secondary hover:bg-white/5 hover:text-text-primary"
                                aria-label="Show all project notes"
                                @click="clearNotesTaskFilter">
                                <XMarkIcon class="h-4 w-4" />
                            </button>
                        </div>
                        <SecondaryButton
                            v-if="canCreateNotes()"
                            :icon="PlusIcon"
                            @click="projectNotesListRef?.openCreate()">
                            New note
                        </SecondaryButton>
                    </div>
                </template>
            </CardTitle>
            <Card class="mt-3">
                <NoteList
                    ref="projectNotesListRef"
                    :project-id="projectId"
                    :task-id="effectiveNotesFilterTaskId"
                    :project-tasks-tab="activeTab"
                    :show-top-create-action="false" />
            </Card>
        </MainContainer>
        <MainContainer v-if="canViewProjectMembers()" class="pt-6 pb-8">
            <CardTitle title="Project Members" :icon="UserGroupIcon">
                <template #actions>
                    <SecondaryButton :icon="PlusIcon" @click="createProjectMember = true">
                        Add Member
                    </SecondaryButton>
                    <ProjectMemberCreateModal
                        v-model:show="createProjectMember"
                        :project-id="projectId"
                        :existing-members="projectMembers" />
                </template>
            </CardTitle>
            <Card class="mt-3">
                <ProjectMemberTable :project-members="projectMembers" :project-id="projectId" />
            </Card>
        </MainContainer>
    </AppLayout>
</template>
