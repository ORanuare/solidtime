<script setup lang="ts">
import { computed } from 'vue';
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import OrganizationSwitcher from '@/Components/OrganizationSwitcher.vue';
import TimeTracker from '@/Components/TimeTracker.vue';
import TimerFocusNotesPanel from '@/Components/Common/Note/TimerFocusNotesPanel.vue';
import { Button } from '@/packages/ui/src/Buttons';
import { formatDuration } from '@/packages/ui/src/utils/time';
import { useTimerFocus } from '@/utils/useTimerFocus';
import { useTimerFocusTodayWorked } from '@/utils/useTimerFocusTodayWorked';
import { useCommandPalette } from '@/utils/useCommandPalette';
import { onKeyStroke } from '@vueuse/core';
import { canCreateNotes, canViewNotes } from '@/utils/permissions';

const { isTimerFocusOpen, transformOrigin, close } = useTimerFocus();
const { totalSeconds, isLoading: todayWorkedLoading } = useTimerFocusTodayWorked(isTimerFocusOpen);
const { openPalette, isOpen: paletteIsOpen } = useCommandPalette();

const todayWorkedDisplay = computed(() =>
    formatDuration(todayWorkedLoading.value ? 0 : totalSeconds.value)
);

onKeyStroke('Escape', (e) => {
    if (!isTimerFocusOpen.value || paletteIsOpen.value) {
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
                            class="absolute right-4 top-4 z-10 flex shrink-0 flex-col items-end gap-0 sm:right-6 sm:top-6"
                            data-testid="timer_focus_today_worked"
                            :aria-label="`Time worked today, ${todayWorkedDisplay}`">
                            <span
                                class="text-xs font-medium uppercase leading-none tracking-wide text-text-tertiary"
                                >Today</span
                            >
                            <span
                                class="font-semibold tabular-nums text-xl leading-none tracking-tight text-text-primary sm:text-2xl"
                                >{{ todayWorkedDisplay }}</span
                            >
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
