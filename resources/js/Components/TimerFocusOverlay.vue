<script setup lang="ts">
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import OrganizationSwitcher from '@/Components/OrganizationSwitcher.vue';
import TimeTracker from '@/Components/TimeTracker.vue';
import { Button } from '@/packages/ui/src/Buttons';
import { useTimerFocus } from '@/utils/useTimerFocus';
import { useCommandPalette } from '@/utils/useCommandPalette';
import { onKeyStroke } from '@vueuse/core';

const { isTimerFocusOpen, transformOrigin, close } = useTimerFocus();
const { openPalette, isOpen: paletteIsOpen } = useCommandPalette();

onKeyStroke('Escape', (e) => {
    if (!isTimerFocusOpen.value || paletteIsOpen.value) {
        return;
    }
    e.preventDefault();
    close();
});
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
                    <div class="flex min-w-0 flex-1 items-center justify-end gap-1">
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
                <div class="flex min-h-0 flex-1 items-center justify-center overflow-y-auto px-4 py-6">
                    <TimeTracker variant="focus" />
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
