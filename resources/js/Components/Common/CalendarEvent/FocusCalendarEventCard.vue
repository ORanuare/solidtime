<script setup lang="ts">
import { computed } from 'vue';
import { InformationCircleIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/20/solid';
import ProjectBadge from '@/packages/ui/src/Project/ProjectBadge.vue';
import { ChevronRightIcon } from '@heroicons/vue/16/solid';
import type { OrgCalendarEvent, Project, Task } from '@/packages/api/src';
import type { TimeFormat } from '@/packages/ui/src/utils/time';
import { formatOrgCalendarEventSchedule } from '@/utils/formatOrgCalendarEventSchedule';
import {
    canDeleteCalendarEvents,
    canUpdateCalendarEvents,
} from '@/utils/permissions';
import { getCurrentUserId } from '@/utils/useUser';
import {
    orgCalendarEventAttachmentLinkCount,
    orgCalendarEventAttachmentSummaryForList,
} from '@/utils/orgCalendarEventAssignments';
import { twMerge } from 'tailwind-merge';

const props = withDefaults(
    defineProps<{
        calendarEvent: OrgCalendarEvent;
        project?: Project;
        task?: Task;
        orgTimeFormat: TimeFormat;
        /** Dense row for timer focus floating widget */
        compact?: boolean;
        /** Entire row opens details (no per-row action icons). Use with `compact`. */
        interactive?: boolean;
        /** Happening now (timer-focus list). */
        ongoing?: boolean;
    }>(),
    { compact: false, interactive: false, ongoing: false }
);

const emit = defineEmits<{
    (e: 'edit'): void;
    (e: 'delete'): void;
    (e: 'viewDetails'): void;
}>();

const currentUserId = getCurrentUserId();

const canEdit = computed(
    () => canUpdateCalendarEvents() && props.calendarEvent.user_id === currentUserId
);
const canDelete = computed(
    () => canDeleteCalendarEvents() && props.calendarEvent.user_id === currentUserId
);

const whenLabel = computed(() =>
    formatOrgCalendarEventSchedule(props.calendarEvent, props.orgTimeFormat)
);

/** Where the event is attached (lists + timer-focus rows). */
const attachmentSummary = computed(() =>
    orgCalendarEventAttachmentSummaryForList(
        props.calendarEvent,
        props.project,
        props.task
    )
);

const attachmentLinkCount = computed(() =>
    orgCalendarEventAttachmentLinkCount(props.calendarEvent)
);

const showMultiLinkBadge = computed(
    () => attachmentLinkCount.value > 1
);

const interactiveRowClass = computed(() =>
    twMerge(
        'w-full rounded-lg border border-default/80 bg-card-background/90 px-2.5 py-2 shadow-sm backdrop-blur-sm text-left transition',
        'hover:bg-card-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
    )
);

function rowAriaLabel() {
    const t = props.calendarEvent.title?.trim() || 'Event';
    const ongoing = props.ongoing ? ' Ongoing.' : '';
    const meta = `${whenLabel.value} · ${attachmentSummary.value}`;
    return `${t}.${ongoing} ${meta}. View details.`;
}
</script>

<template>
    <button
        v-if="interactive"
        type="button"
        :class="interactiveRowClass"
        :data-calendar-event-id="calendarEvent.id"
        data-testid="timer_focus_event_row"
        :aria-label="rowAriaLabel()"
        @click="emit('viewDetails')">
        <div class="min-w-0 space-y-0.5">
            <div class="flex min-w-0 items-center gap-1.5">
                <p class="min-w-0 flex-1 truncate text-xs font-semibold leading-tight text-text-primary">
                    {{ calendarEvent.title }}
                </p>
                <div class="flex shrink-0 items-center gap-1.5">
                    <span
                        v-if="showMultiLinkBadge"
                        class="rounded-full bg-text-secondary/12 px-1.5 py-0.5 text-[0.55rem] font-bold tabular-nums leading-none tracking-wide text-text-secondary"
                        :title="`${attachmentLinkCount} linked items`">
                        {{ attachmentLinkCount }} links
                    </span>
                    <span
                        v-if="ongoing"
                        class="rounded-full bg-emerald-500/15 px-1.5 py-0.5 text-[0.55rem] font-bold uppercase leading-none tracking-wide text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200">
                        Ongoing
                    </span>
                </div>
            </div>
            <p
                class="min-w-0 text-[0.65rem] leading-snug"
                :class="showMultiLinkBadge ? 'line-clamp-2' : 'truncate'">
                <span class="tabular-nums text-text-tertiary">{{ whenLabel }}</span>
                <span class="mx-0.5 text-text-tertiary/55" aria-hidden="true">·</span>
                <span class="text-text-secondary" :title="attachmentSummary">{{ attachmentSummary }}</span>
            </p>
            <div
                v-if="calendarEvent.visibility === 'private'"
                class="text-[0.65rem] text-text-tertiary">
                Private
            </div>
        </div>
    </button>
    <div
        v-else
        :class="
            compact
                ? 'rounded-lg border border-default/80 bg-card-background/90 px-2.5 py-2 shadow-sm backdrop-blur-sm'
                : 'rounded-lg border border-default bg-card-background p-3 shadow-sm'
        "
        :data-calendar-event-id="calendarEvent.id">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex-1 space-y-0.5">
                <p
                    :class="
                        compact
                            ? 'truncate text-xs font-semibold text-text-primary'
                            : 'truncate text-sm font-medium text-text-primary'
                    ">
                    {{ calendarEvent.title }}
                </p>
                <p
                    :class="
                        compact
                            ? 'text-[0.65rem] tabular-nums text-text-tertiary'
                            : 'text-xs tabular-nums text-text-tertiary'
                    ">
                    {{ whenLabel }}
                </p>
                <div
                    v-if="!compact"
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-text-tertiary">
                    <span class="min-w-0 truncate font-medium text-text-secondary">
                        {{ calendarEvent.user_name }}
                    </span>
                    <span class="text-text-tertiary">· {{ calendarEvent.eventable_label }}</span>
                    <span v-if="calendarEvent.visibility === 'private'" class="shrink-0">· Private</span>
                </div>
                <div v-else class="flex flex-wrap items-center gap-x-1.5 text-[0.65rem] text-text-tertiary">
                    <span v-if="calendarEvent.visibility === 'private'" class="shrink-0">Private</span>
                </div>
                <ProjectBadge
                    v-if="project && !compact && attachmentLinkCount <= 1"
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
            <div :class="compact ? 'flex shrink-0 gap-0' : 'flex shrink-0 flex-col gap-0.5'">
                <button
                    type="button"
                    :class="
                        compact
                            ? 'rounded p-0.5 text-text-secondary hover:bg-white/5'
                            : 'rounded p-1 text-text-secondary hover:bg-white/5'
                    "
                    aria-label="View event details"
                    data-testid="timer_focus_event_details"
                    @click.stop="emit('viewDetails')">
                    <InformationCircleIcon :class="compact ? 'h-3.5 w-3.5' : 'h-4 w-4'" />
                </button>
                <button
                    v-if="canEdit"
                    type="button"
                    :class="
                        compact
                            ? 'rounded p-0.5 text-text-secondary hover:bg-white/5'
                            : 'rounded p-1 text-text-secondary hover:bg-white/5'
                    "
                    aria-label="Edit event"
                    data-testid="timer_focus_event_edit"
                    @click.stop="emit('edit')">
                    <PencilSquareIcon :class="compact ? 'h-3.5 w-3.5' : 'h-4 w-4'" />
                </button>
                <button
                    v-if="canDelete"
                    type="button"
                    :class="
                        compact
                            ? 'rounded p-0.5 text-destructive hover:bg-white/5'
                            : 'rounded p-1 text-destructive hover:bg-white/5'
                    "
                    aria-label="Delete event"
                    data-testid="timer_focus_event_delete"
                    @click.stop="emit('delete')">
                    <TrashIcon :class="compact ? 'h-3.5 w-3.5' : 'h-4 w-4'" />
                </button>
            </div>
        </div>
    </div>
</template>
