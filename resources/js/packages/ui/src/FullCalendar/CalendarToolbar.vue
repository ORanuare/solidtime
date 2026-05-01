<script setup lang="ts">
import { Button } from '..';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { Tabs, TabsList } from '../tabs';
import TabBarItem from '../TabBar/TabBarItem.vue';
import CalendarSettingsPopover from './CalendarSettingsPopover.vue';
import type { CalendarSettings } from './calendarSettings';

defineProps<{
    viewTitle: string;
    activeView: string;
    settings: CalendarSettings;
    showTimeEntries: boolean;
    showScheduledEvents: boolean;
}>();

const emit = defineEmits<{
    prev: [];
    next: [];
    today: [];
    'change-view': [view: string];
    'update:settings': [value: CalendarSettings];
    'update:showTimeEntries': [value: boolean];
    'update:showScheduledEvents': [value: boolean];
}>();
</script>

<template>
    <div class="flex items-center justify-between bg-default-background px-2 py-1.5 gap-2 flex-wrap">
        <!-- Left: Navigation -->
        <div class="flex items-center gap-1 shrink-0">
            <Button
                variant="outline"
                size="sm"
                class="h-8 w-8 p-0"
                aria-label="Previous"
                @click="emit('prev')">
                <ChevronLeft class="h-4 w-4" />
            </Button>
            <Button
                variant="outline"
                size="sm"
                class="h-8 w-8 p-0"
                aria-label="Next"
                @click="emit('next')">
                <ChevronRight class="h-4 w-4" />
            </Button>
            <Button variant="outline" size="sm" @click="emit('today')"> today </Button>
        </div>

        <!-- Center: Title -->
        <span data-testid="calendar-title" class="text-base font-semibold text-foreground flex-1 text-center min-w-[8rem]">{{
            viewTitle
        }}</span>

        <!-- Right: visibility + View switcher + Settings -->
        <div class="flex items-center gap-1 shrink-0 flex-wrap justify-end">
            <div
                class="inline-flex items-center gap-0.5 rounded-lg border border-border bg-tertiary/55 p-1 dark:bg-secondary/55"
                role="group"
                aria-label="Calendar layers">
                <button
                    type="button"
                    class="inline-flex h-8 items-center rounded-md border px-2.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:px-3"
                    :class="
                        showTimeEntries
                            ? 'border-input-border bg-tab-background text-text-primary shadow-sm dark:shadow-black/20'
                            : 'border-transparent bg-transparent font-medium text-text-tertiary hover:bg-background/70 hover:text-text-secondary'
                    "
                    :aria-pressed="showTimeEntries"
                    :aria-label="
                        showTimeEntries
                            ? 'Time entries are shown. Click to hide.'
                            : 'Time entries are hidden. Click to show.'
                    "
                    data-testid="calendar_toggle_time_entries"
                    @click="emit('update:showTimeEntries', !showTimeEntries)">
                    <span
                        class="mr-1.5 inline-block h-2 w-2 shrink-0 rounded-full border-2"
                        :class="
                            showTimeEntries
                                ? 'border-accent-600 bg-accent-500 shadow-[0_0_0_1px_rgba(0,0,0,0.06)] dark:border-accent-400 dark:bg-accent-400'
                                : 'border-border-tertiary bg-muted/30 dark:bg-transparent'
                        "
                        aria-hidden="true" />
                    <span class="whitespace-nowrap">Time entries</span>
                </button>
                <button
                    type="button"
                    class="inline-flex h-8 items-center rounded-md border px-2.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:px-3"
                    :class="
                        showScheduledEvents
                            ? 'border-input-border bg-tab-background text-text-primary shadow-sm dark:shadow-black/20'
                            : 'border-transparent bg-transparent font-medium text-text-tertiary hover:bg-background/70 hover:text-text-secondary'
                    "
                    :aria-pressed="showScheduledEvents"
                    :aria-label="
                        showScheduledEvents
                            ? 'Events are shown. Click to hide.'
                            : 'Events are hidden. Click to show.'
                    "
                    data-testid="calendar_toggle_events"
                    @click="emit('update:showScheduledEvents', !showScheduledEvents)">
                    <span
                        class="mr-1.5 inline-block h-2 w-2 shrink-0 rounded-full border-2"
                        :class="
                            showScheduledEvents
                                ? 'border-indigo-700 bg-indigo-500 shadow-[0_0_0_1px_rgba(0,0,0,0.06)] dark:border-indigo-400 dark:bg-indigo-400'
                                : 'border-border-tertiary bg-muted/30 dark:bg-transparent'
                        "
                        aria-hidden="true" />
                    <span class="whitespace-nowrap">Events</span>
                </button>
            </div>
            <Tabs
                :model-value="activeView"
                @update:model-value="(v) => emit('change-view', String(v))">
                <TabsList class="flex items-center space-x-0.5 sm:space-x-1">
                    <TabBarItem value="timeGridWeek">week</TabBarItem>
                    <TabBarItem value="timeGridDay">day</TabBarItem>
                </TabsList>
            </Tabs>
            <CalendarSettingsPopover
                :settings="settings"
                @update:settings="(v) => emit('update:settings', v)" />
        </div>
    </div>
</template>
