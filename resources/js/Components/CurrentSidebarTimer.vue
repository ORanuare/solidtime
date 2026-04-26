<script setup lang="ts">
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import { storeToRefs } from 'pinia';
import { computed, ref } from 'vue';
import dayjs from 'dayjs';
import { ArrowsPointingOutIcon } from '@heroicons/vue/20/solid';
import { formatDuration } from '@/packages/ui/src/utils/time';
import TimeTrackerStartStop from '@/packages/ui/src/TimeTrackerStartStop.vue';
import TimeTrackerIconCircleButton from '@/packages/ui/src/TimeTrackerIconCircleButton.vue';
import { Button, buttonVariants } from '@/packages/ui/src/Buttons';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/packages/ui/src/tooltip';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { useTimerFocus } from '@/utils/useTimerFocus';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { canUpdateTasks } from '@/utils/permissions';
import AlertDialog from '@/Components/ui/alert-dialog/AlertDialog.vue';
import AlertDialogAction from '@/Components/ui/alert-dialog/AlertDialogAction.vue';
import AlertDialogCancel from '@/Components/ui/alert-dialog/AlertDialogCancel.vue';
import AlertDialogContent from '@/Components/ui/alert-dialog/AlertDialogContent.vue';
import AlertDialogDescription from '@/Components/ui/alert-dialog/AlertDialogDescription.vue';
import AlertDialogFooter from '@/Components/ui/alert-dialog/AlertDialogFooter.vue';
import AlertDialogHeader from '@/Components/ui/alert-dialog/AlertDialogHeader.vue';
import AlertDialogTitle from '@/Components/ui/alert-dialog/AlertDialogTitle.vue';

const timerFocus = useTimerFocus();

function onOpenTimerFocus(e: MouseEvent) {
    timerFocus.open(e.currentTarget instanceof HTMLElement ? e.currentTarget : null);
}

const store = useCurrentTimeEntryStore();
const { currentTimeEntry, now, isActive } = storeToRefs(store);
const { setActiveState } = store;
const { tasks } = useTasksQuery();

const timerTask = computed(
    () => tasks.value.find((t) => t.id === currentTimeEntry.value.task_id) ?? null
);
const showStopAndComplete = computed(
    () =>
        isActive.value &&
        timerTask.value != null &&
        !timerTask.value.is_done &&
        canUpdateTasks()
);

const stopAndCompleteDialogOpen = ref(false);

async function onStopAndCompleteConfirmed() {
    const t = timerTask.value;
    if (!t) {
        return;
    }
    await store.stopTimerAndComplete({ id: t.id, name: t.name });
}

const currentTime = computed(() => {
    if (now.value && currentTimeEntry.value.start) {
        const startTime = dayjs(currentTimeEntry.value.start);
        const diff = now.value.diff(startTime, 's');
        return formatDuration(diff);
    }
    return formatDuration(0);
});

const isRunningInDifferentOrganization = computed(() => {
    return (
        currentTimeEntry.value.organization_id &&
        getCurrentOrganizationId() &&
        currentTimeEntry.value.organization_id !== getCurrentOrganizationId()
    );
});
</script>

<template>
    <div>
    <div class="pt-3 pb-2.5 px-2 flex justify-between items-center relative">
        <div
            v-if="isRunningInDifferentOrganization"
            class="absolute w-full h-full backdrop-blur-sm z-10 flex items-center justify-center">
            <div
                class="w-full h-[calc(100%+10px)] absolute bg-default-background opacity-75 backdrop-blur-sm"></div>
            <div class="flex space-x-3 items-center w-full z-20 justify-center">
                <span class="text-xs text-center text-text-primary">
                    The Timer is running in a different organization.
                </span>
            </div>
        </div>
        <div>
            <div class="text-text-secondary font-medium text-xs">Current Timer</div>
            <div class="text-text-primary font-medium text-base">
                {{ currentTime }}
            </div>
        </div>
        <div class="flex items-center gap-1.5 shrink-0">
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8 text-icon-default"
                            data-testid="timer_focus_enter_sidebar"
                            aria-label="Open timer focus mode"
                            @click="onOpenTimerFocus">
                            <ArrowsPointingOutIcon class="h-4 w-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Focus mode</TooltipContent>
                </Tooltip>
            </TooltipProvider>
            <div class="flex items-center gap-1.5">
                <TimeTrackerStartStop
                    :active="isActive"
                    size="base"
                    variant="secondary"
                    @changed="setActiveState"></TimeTrackerStartStop>
                <TooltipProvider v-if="showStopAndComplete">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <TimeTrackerIconCircleButton
                                data-testid="timer_stop_and_complete_sidebar"
                                size="base"
                                along-secondary-stop
                                aria-label="Stop timer and mark task complete"
                                @click="stopAndCompleteDialogOpen = true" />
                        </TooltipTrigger>
                        <TooltipContent>Stop and mark task complete (asks confirmation)</TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
        </div>
    </div>
    <AlertDialog v-model:open="stopAndCompleteDialogOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Stop timer and mark task complete?</AlertDialogTitle>
                <AlertDialogDescription>
                    <template v-if="timerTask">
                        This will stop the timer and mark “{{ timerTask.name }}” as done.
                    </template>
                    <template v-else> This will stop the timer and mark the task as done. </template>
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    :class="buttonVariants({ variant: 'success' })"
                    @click="onStopAndCompleteConfirmed">Stop and complete</AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
    </div>
</template>
