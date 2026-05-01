<script setup lang="ts">
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { Button, buttonVariants } from '@/packages/ui/src/Buttons';
import AlertDialog from '@/Components/ui/alert-dialog/AlertDialog.vue';
import AlertDialogAction from '@/Components/ui/alert-dialog/AlertDialogAction.vue';
import AlertDialogCancel from '@/Components/ui/alert-dialog/AlertDialogCancel.vue';
import AlertDialogContent from '@/Components/ui/alert-dialog/AlertDialogContent.vue';
import AlertDialogDescription from '@/Components/ui/alert-dialog/AlertDialogDescription.vue';
import AlertDialogFooter from '@/Components/ui/alert-dialog/AlertDialogFooter.vue';
import AlertDialogHeader from '@/Components/ui/alert-dialog/AlertDialogHeader.vue';
import AlertDialogTitle from '@/Components/ui/alert-dialog/AlertDialogTitle.vue';
import ProjectBadge from '@/packages/ui/src/Project/ProjectBadge.vue';
import { ChevronRightIcon } from '@heroicons/vue/16/solid';
import type { OrgCalendarEvent, Project, Task } from '@/packages/api/src';
import type { TimeFormat } from '@/packages/ui/src/utils/time';
import { computed, ref, watch } from 'vue';
import { formatOrgCalendarEventSchedule } from '@/utils/formatOrgCalendarEventSchedule';
import {
    canDeleteCalendarEvents,
    canUpdateCalendarEvents,
} from '@/utils/permissions';
import { getCurrentUserId } from '@/utils/useUser';

const show = defineModel('show', { default: false });

const props = defineProps<{
    calendarEvent: OrgCalendarEvent;
    project?: Project;
    task?: Task;
    orgTimeFormat: TimeFormat;
}>();

const emit = defineEmits<{
    (e: 'edit', ev: OrgCalendarEvent): void;
    (e: 'delete', ev: OrgCalendarEvent): void;
}>();

const currentUserId = getCurrentUserId();

const canEdit = computed(
    () => canUpdateCalendarEvents() && props.calendarEvent.user_id === currentUserId
);

const canDelete = computed(
    () => canDeleteCalendarEvents() && props.calendarEvent.user_id === currentUserId
);

const scheduleLabel = computed(() =>
    formatOrgCalendarEventSchedule(props.calendarEvent, props.orgTimeFormat)
);

const visibilityLabel = computed(() =>
    props.calendarEvent.visibility === 'private' ? 'Private' : 'Shared'
);

function onClose() {
    show.value = false;
}

function onEdit() {
    emit('edit', props.calendarEvent);
    show.value = false;
}

const deleteConfirmOpen = ref(false);

watch(show, (open) => {
    if (!open) {
        deleteConfirmOpen.value = false;
    }
});

function requestDeleteConfirm() {
    deleteConfirmOpen.value = true;
}

function confirmDelete() {
    deleteConfirmOpen.value = false;
    emit('delete', props.calendarEvent);
}
</script>

<template>
    <DialogModal :show="show" max-width="lg" @close="onClose">
        <template #title>{{ calendarEvent.title }}</template>
        <template #description>{{ scheduleLabel }}</template>
        <template #content>
            <div class="flex flex-col gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-text-tertiary">
                        Description
                    </p>
                    <p
                        v-if="calendarEvent.description?.trim()"
                        class="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-text-primary">
                        {{ calendarEvent.description }}
                    </p>
                    <p v-else class="mt-1 text-sm italic text-text-tertiary">No description</p>
                </div>
                <div class="flex flex-wrap gap-x-3 gap-y-1 text-sm text-text-secondary">
                    <span class="font-medium text-text-primary">{{ calendarEvent.user_name }}</span>
                    <span class="text-text-tertiary">·</span>
                    <span>{{ calendarEvent.eventable_label }}</span>
                    <span class="text-text-tertiary">·</span>
                    <span>{{ visibilityLabel }}</span>
                </div>
                <ProjectBadge
                    v-if="project"
                    class="max-w-full pt-0.5"
                    size="base"
                    :name="project.name"
                    :color="project.color">
                    <div class="flex min-w-0 items-center gap-0.5">
                        <span class="truncate text-xs font-medium text-text-primary">{{ project.name }}</span>
                        <ChevronRightIcon
                            v-if="task"
                            class="h-3.5 w-3.5 shrink-0 text-text-secondary" />
                        <span v-if="task" class="min-w-0 truncate text-xs font-medium text-text-primary">{{
                            task.name
                        }}</span>
                    </div>
                </ProjectBadge>
            </div>
        </template>
        <template #footer>
            <div
                class="flex w-full min-w-0 flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-x-3">
                <Button
                    v-if="canDelete"
                    variant="destructive"
                    type="button"
                    class="w-full sm:w-auto"
                    data-testid="calendar_event_detail_delete"
                    @click="requestDeleteConfirm">
                    Delete event
                </Button>
                <div
                    class="flex w-full flex-col-reverse gap-2 sm:w-auto sm:flex-row sm:justify-end sm:gap-x-2">
                    <SecondaryButton type="button" @click="onClose">Close</SecondaryButton>
                    <PrimaryButton v-if="canEdit" type="button" @click="onEdit">Edit event</PrimaryButton>
                </div>
            </div>
        </template>
    </DialogModal>

    <AlertDialog v-if="canDelete" v-model:open="deleteConfirmOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Delete this event?</AlertDialogTitle>
                <AlertDialogDescription>
                    “{{ calendarEvent.title }}” will be permanently removed. This cannot be undone.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    :class="buttonVariants({ variant: 'destructive' })"
                    data-testid="calendar_event_delete_confirm"
                    @click="confirmDelete">
                    Delete event
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
