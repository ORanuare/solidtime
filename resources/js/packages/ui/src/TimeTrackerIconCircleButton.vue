<script setup lang="ts">
import { FlagIcon } from '@heroicons/vue/20/solid';
import {
    timeTrackerButtonIconSizeClass,
    timeTrackerButtonVariants,
    type TimeTrackerButtonVariantProps,
} from './timeTrackerButtonVariants';
import { cn } from './utils/cn';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        size?: TimeTrackerButtonVariantProps['size'];
        /** e.g. data-testid="timer_stop_and_complete" */
        dataTestid?: string;
        ariaLabel?: string;
        /**
         * Match the **secondary** (grey) stop: same `variant="secondary"` chrome; different icon.
         * Main timer uses the default `complete` (emerald) next to the red stop.
         */
        alongSecondaryStop?: boolean;
    }>(),
    { size: 'base', dataTestid: undefined, ariaLabel: undefined, alongSecondaryStop: false }
);

const emit = defineEmits<{
    click: [e: MouseEvent];
}>();

const buttonVariant = computed(() => (props.alongSecondaryStop ? 'secondary' : 'complete'));
</script>

<template>
    <button
        type="button"
        :data-testid="dataTestid"
        :aria-label="ariaLabel"
        :class="cn(timeTrackerButtonVariants({ variant: buttonVariant, size, active: false }))"
        @click="emit('click', $event)">
        <FlagIcon :class="[timeTrackerButtonIconSizeClass[props.size ?? 'base'], 'shrink-0']" />
    </button>
</template>
