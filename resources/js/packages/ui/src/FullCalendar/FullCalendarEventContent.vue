<script setup lang="ts">
import { computed, inject, type ComputedRef } from 'vue';
import { formatHumanReadableDuration, getDayJsInstance } from '../utils/time';
import type { Organization } from '@/packages/api/src';

const props = defineProps<{
    /** Distinguishes logged time vs planned calendar events in the grid */
    eventKind?: 'time_entry' | 'scheduled_event';
    title: string;
    projectName?: string | null;
    taskName?: string | null;
    clientName?: string | null;
    durationSeconds?: number;
    start?: string | Date | null;
    end?: string | Date | null;
}>();

const effectiveDurationSeconds = computed(() => {
    if (typeof props.durationSeconds === 'number') {
        return props.durationSeconds;
    }
    if (props.start && props.end) {
        const end = getDayJsInstance()(props.end as unknown as string | Date);
        const start = getDayJsInstance()(props.start as unknown as string | Date);
        const minutes = end.diff(start, 'minutes');
        return minutes * 60;
    }
    return 0;
});

const organization = inject('organization') as ComputedRef<Organization | undefined> | undefined;
const intervalFormat = computed(() => organization?.value?.interval_format);
const numberFormat = computed(() => organization?.value?.number_format);

const formattedDuration = computed(() =>
    formatHumanReadableDuration(
        effectiveDurationSeconds.value,
        intervalFormat.value,
        numberFormat.value
    )
);

const kindLabel = computed(() => {
    if (props.eventKind === 'scheduled_event') {
        return 'Event';
    }
    if (props.eventKind === 'time_entry') {
        return 'Tracked';
    }
    return null;
});
</script>

<template>
    <div class="text-2xs leading-tight px-0.5 py-1">
        <div v-if="kindLabel" class="mb-0.5 flex items-center justify-start">
            <span
                class="rounded px-1 py-px text-[0.55rem] font-bold uppercase tracking-wide leading-none"
                :class="
                    eventKind === 'scheduled_event'
                        ? 'bg-indigo-600/35 text-indigo-50 dark:bg-indigo-500/35 dark:text-indigo-50'
                        : 'bg-black/[0.12] text-text-secondary dark:bg-white/12 dark:text-text-secondary'
                ">
                {{ kindLabel }}
            </span>
        </div>
        <div class="font-semibold">{{ title }}</div>
        <div v-if="projectName" class="font-medium opacity-90">
            {{ projectName }}
        </div>
        <div v-if="taskName" class="font-medium">
            {{ taskName }}
        </div>
        <div v-if="clientName" class="opacity-85">
            {{ clientName }}
        </div>
        <div class="opacity-90" data-duration>
            {{ formattedDuration }}
        </div>
    </div>
</template>
