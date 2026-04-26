<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { ChevronDownIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import { ChevronRightIcon } from '@heroicons/vue/16/solid';
import OrganizationSwitcher from '@/Components/OrganizationSwitcher.vue';
import TimeTracker from '@/Components/TimeTracker.vue';
import TimerFocusNotesPanel from '@/Components/Common/Note/TimerFocusNotesPanel.vue';
import { Button } from '@/packages/ui/src/Buttons';
import { Popover, PopoverContent, PopoverTrigger } from '@/packages/ui/src/popover';
import ProjectBadge from '@/packages/ui/src/Project/ProjectBadge.vue';
import { formatDuration, formatStartEnd, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import type { TimeEntry } from '@/packages/api/src';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import { useTimerFocus } from '@/utils/useTimerFocus';
import {
    timerFocusEntryDurationSeconds,
    useTimerFocusTodayWorked,
} from '@/utils/useTimerFocusTodayWorked';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { useCommandPalette } from '@/utils/useCommandPalette';
import { onKeyStroke } from '@vueuse/core';
import { canCreateNotes, canViewNotes } from '@/utils/permissions';
import { twMerge } from 'tailwind-merge';

const { isTimerFocusOpen, transformOrigin, close } = useTimerFocus();
const { totalSeconds, isLoading: todayWorkedLoading, todayEntries } =
    useTimerFocusTodayWorked(isTimerFocusOpen);
const { openPalette, isOpen: paletteIsOpen } = useCommandPalette();
const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();
const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);
const currentTimeEntryStore = useCurrentTimeEntryStore();
const { currentTimeEntry, isActive, now } = storeToRefs(currentTimeEntryStore);

const todayDetailOpen = ref(false);

const todayWorkedDisplay = computed(() =>
    formatDuration(todayWorkedLoading.value ? 0 : totalSeconds.value)
);

function entryRowDurationDisplay(entry: TimeEntry) {
    return formatDuration(timerFocusEntryDurationSeconds(entry, now));
}

function entryRowClass(entry: TimeEntry) {
    const isCurrent =
        isActive.value &&
        entry.id !== '' &&
        entry.id === currentTimeEntry.value.id &&
        entry.end === null;
    return twMerge(
        'flex gap-3 rounded-md border border-transparent px-2 py-2 text-left',
        isCurrent && 'border-accent-300/40 bg-accent-50/80 dark:bg-accent-300/15'
    );
}

function entryProject(entry: TimeEntry) {
    return projects.value.find((p) => p.id === entry.project_id);
}

function entryTask(entry: TimeEntry) {
    return tasks.value.find((t) => t.id === entry.task_id);
}

/** e.g. "April 24" in the user’s locale calendar. */
const todayDateLabel = computed(() => getLocalizedDayJs().format('MMMM D'));

const orgTimeFormat = computed(
    () => organization.value?.time_format ?? ('24-hours' as const)
);

function entryRowTimeRange(entry: TimeEntry) {
    return formatStartEnd(entry.start, entry.end, orgTimeFormat.value);
}

onKeyStroke('Escape', (e) => {
    if (!isTimerFocusOpen.value || paletteIsOpen.value) {
        return;
    }
    if (todayDetailOpen.value) {
        todayDetailOpen.value = false;
        e.preventDefault();
        return;
    }
    e.preventDefault();
    close();
});

const showNotesColumn = computed(() => canViewNotes() || canCreateNotes());
</script>

<template>
    <Teleport to="body">
        <Transition name="timer-focus">
            <div
                v-if="isTimerFocusOpen"
                class="fixed inset-0 z-[80] flex h-full flex-col bg-default-background"
                role="dialog"
                aria-modal="true"
                aria-label="Timer focus"
                data-testid="timer_focus_view"
                :style="{ transformOrigin }">
                <div
                    class="flex w-full shrink-0 items-center gap-2 border-b border-b-default-background-separator px-3 py-1.5 text-text-secondary">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 shrink-0 text-text-primary"
                        data-testid="timer_focus_exit"
                        aria-label="Close timer focus"
                        @click="close">
                        <XMarkIcon class="h-4 w-4 text-icon-default" />
                    </Button>
                    <span class="text-sm font-medium text-text-primary">Focus</span>
                    <div class="flex min-w-0 flex-1 items-center justify-end gap-2">
                        <OrganizationSwitcher />
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7 shrink-0"
                            data-testid="command_palette_button_focus"
                            @click="openPalette">
                            <MagnifyingGlassIcon class="h-4 w-4 text-icon-default" />
                        </Button>
                    </div>
                </div>
                <div
                    class="flex min-h-0 flex-1 flex-col lg:flex-row">
                    <div
                        class="relative flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto px-4 py-6">
                        <div
                            class="absolute right-4 top-4 z-10 flex shrink-0 flex-col items-end sm:right-6 sm:top-6">
                            <Popover v-model:open="todayDetailOpen">
                                <PopoverTrigger as-child>
                                    <button
                                        type="button"
                                        class="group flex cursor-pointer items-center gap-2 rounded-lg border border-card-border bg-card-background px-3 py-2 text-right shadow-sm transition hover:bg-tertiary/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:hover:bg-secondary/80"
                                        data-testid="timer_focus_today_trigger"
                                        :aria-expanded="todayDetailOpen"
                                        aria-controls="timer-focus-today-detail"
                                        :class="
                                            todayDetailOpen &&
                                            'bg-tertiary/60 ring-1 ring-card-border dark:bg-secondary'
                                        "
                                        :aria-label="`Time worked today, ${todayWorkedDisplay}. Show entries`">
                                        <div class="flex min-w-0 flex-col items-end gap-0">
                                            <span
                                                class="text-[0.65rem] font-semibold uppercase leading-none tracking-wide text-text-tertiary"
                                                >Today</span
                                            >
                                            <span
                                                data-testid="timer_focus_today_worked"
                                                class="font-semibold tabular-nums text-xl leading-none tracking-tight text-text-primary sm:text-2xl"
                                                >{{ todayWorkedDisplay }}</span
                                            >
                                        </div>
                                        <ChevronDownIcon
                                            class="h-5 w-5 shrink-0 text-text-tertiary transition group-hover:text-text-secondary"
                                            :class="todayDetailOpen ? 'rotate-180' : ''"
                                            aria-hidden="true" />
                                    </button>
                                </PopoverTrigger>
                                <PopoverContent
                                    id="timer-focus-today-detail"
                                    class="w-[min(360px,calc(100vw-2rem))] p-0"
                                    align="end"
                                    side="bottom"
                                    :side-offset="8">
                                    <div class="border-b border-border-tertiary px-3 py-2">
                                        <h2 class="text-sm font-semibold text-text-primary">Today</h2>
                                        <p class="text-xs text-text-secondary">
                                            {{ todayDateLabel }}
                                        </p>
                                        <p
                                            v-if="todayWorkedLoading"
                                            class="mt-0.5 text-xs text-text-secondary">
                                            Loading…
                                        </p>
                                    </div>
                                    <div class="max-h-[min(320px,50vh)] overflow-y-auto p-2">
                                        <p
                                            v-if="todayWorkedLoading && todayEntries.length === 0"
                                            class="px-2 py-6 text-center text-sm text-text-secondary">
                                            Loading…
                                        </p>
                                        <p
                                            v-else-if="!todayWorkedLoading && todayEntries.length === 0"
                                            class="px-2 py-6 text-center text-sm text-text-secondary">
                                            No time entries today yet.
                                        </p>
                                        <ul
                                            v-else
                                            class="flex list-none flex-col gap-1"
                                            data-testid="timer_focus_today_entries">
                                            <li v-for="entry in todayEntries" :key="entry.id">
                                                <div :class="entryRowClass(entry)">
                                                    <div class="min-w-0 flex-1 space-y-1">
                                                        <p
                                                            class="truncate text-sm font-medium text-text-primary">
                                                            {{
                                                                entry.description?.trim()
                                                                    ? entry.description
                                                                    : 'No description'
                                                            }}
                                                        </p>
                                                        <p
                                                            class="text-xs text-text-tertiary">
                                                            <span class="tabular-nums">{{
                                                                entryRowTimeRange(entry)
                                                            }}</span>
                                                        </p>
                                                        <ProjectBadge
                                                            class="max-w-full"
                                                            size="base"
                                                            :name="entryProject(entry)?.name"
                                                            :color="entryProject(entry)?.color">
                                                            <div
                                                                v-if="entryProject(entry)"
                                                                class="flex min-w-0 items-center gap-0.5">
                                                                <span
                                                                    class="truncate text-xs font-medium text-text-primary">
                                                                    {{ entryProject(entry)?.name }}
                                                                </span>
                                                                <ChevronRightIcon
                                                                    v-if="entryTask(entry)"
                                                                    class="h-3.5 w-3.5 shrink-0 text-text-secondary" />
                                                                <span
                                                                    v-if="entryTask(entry)"
                                                                    class="min-w-0 truncate text-xs font-medium text-text-primary">
                                                                    {{ entryTask(entry)?.name }}
                                                                </span>
                                                            </div>
                                                            <span v-else class="text-xs text-text-tertiary"
                                                                >No project</span
                                                            >
                                                        </ProjectBadge>
                                                    </div>
                                                    <span
                                                        class="shrink-0 tabular-nums text-sm font-semibold text-text-primary">
                                                        {{ entryRowDurationDisplay(entry) }}
                                                    </span>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>
                                </PopoverContent>
                            </Popover>
                        </div>
                        <div class="flex min-h-0 flex-1 items-center justify-center">
                            <TimeTracker variant="focus" />
                        </div>
                    </div>
                    <aside
                        v-if="showNotesColumn"
                        class="flex h-48 min-h-0 w-full shrink-0 flex-col border-t border-default bg-default-background lg:h-auto lg:max-w-md lg:border-l lg:border-t-0 xl:max-w-lg">
                        <TimerFocusNotesPanel class="h-full min-h-0" />
                    </aside>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.timer-focus-enter-active,
.timer-focus-leave-active {
    transition:
        opacity 0.38s cubic-bezier(0.16, 1, 0.3, 1),
        transform 0.5s cubic-bezier(0.16, 1, 0.3, 1);
}

.timer-focus-enter-from,
.timer-focus-leave-to {
    opacity: 0;
    transform: scale(0.88);
}

.timer-focus-leave-active {
    transition:
        opacity 0.28s ease,
        transform 0.34s cubic-bezier(0.4, 0, 1, 1);
}
</style>
