<script setup lang="ts">
import {
    ref,
    watch,
    computed,
    inject,
    type ComputedRef,
    nextTick,
    onMounted,
    onActivated,
    onDeactivated,
    onUnmounted,
} from 'vue';
import { useLocalStorage } from '@vueuse/core';
import { useCssVariable } from '../utils/useCssVariable';
import { getLocalizedDayJs } from '../utils/time';
import { LoadingSpinner, TimeEntryCreateModal, TimeEntryEditModal } from '..';
import FullCalendarDayHeader from './FullCalendarDayHeader.vue';
import CalendarToolbar from './CalendarToolbar.vue';
import CalendarDayColumn from './CalendarDayColumn.vue';
import type { CalendarSettings } from './calendarSettings';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuTrigger,
} from '..';
import {
    PencilIcon,
    DocumentDuplicateIcon,
    TrashIcon,
    ScissorsIcon,
    PlusIcon,
    StopIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';
import type { ActivityPeriod } from './activityTypes';
import { SLOT_HEIGHT, TIME_AXIS_WIDTH, type DayEvent } from './calendarTypes';
import { useCalendarGrid } from './useCalendarGrid';
import { useCalendarNavigation } from './useCalendarNavigation';
import { useCalendarEvents } from './useCalendarEvents';
import { useActivityBoxes } from './useActivityBoxes';
import { useEventDrag } from './useEventDrag';
import { useEventResize } from './useEventResize';
import { useSlotSelection } from './useSlotSelection';
import { useContextMenu } from './useContextMenu';
import type {
    TimeEntry,
    Project,
    Client,
    Task,
    CreateProjectBody,
    CreateClientBody,
    Tag,
    Organization,
    OrgCalendarEvent,
    CreateOrgCalendarEventBody,
} from '@/packages/api/src';
import type { Dayjs } from 'dayjs';
import CalendarEventFormModal from './CalendarEventFormModal.vue';
import CalendarEventDetailModal from './CalendarEventDetailModal.vue';
import AlertDialog from '@/Components/ui/alert-dialog/AlertDialog.vue';
import AlertDialogAction from '@/Components/ui/alert-dialog/AlertDialogAction.vue';
import AlertDialogCancel from '@/Components/ui/alert-dialog/AlertDialogCancel.vue';
import AlertDialogContent from '@/Components/ui/alert-dialog/AlertDialogContent.vue';
import AlertDialogDescription from '@/Components/ui/alert-dialog/AlertDialogDescription.vue';
import AlertDialogFooter from '@/Components/ui/alert-dialog/AlertDialogFooter.vue';
import AlertDialogHeader from '@/Components/ui/alert-dialog/AlertDialogHeader.vue';
import AlertDialogTitle from '@/Components/ui/alert-dialog/AlertDialogTitle.vue';
import { buttonVariants } from '@/packages/ui/src/Buttons';
import { DRAG_THRESHOLD } from './calendarTypes';

/** Grid tracks use minmax(0, 1fr) so columns shrink and events stay inside day bounds. */
function dayColumnGridTemplate(columnCount: number) {
    return `repeat(${columnCount}, minmax(0, 1fr))`;
}

const emit = defineEmits<{
    (e: 'dates-change', payload: { start: Date; end: Date }): void;
    (e: 'refresh'): void;
}>();

const props = withDefaults(
    defineProps<{
        timeEntries: TimeEntry[];
        projects: Project[];
        tasks: Task[];
        clients: Client[];
        tags: Tag[];
        activityPeriods?: ActivityPeriod[];
        loading?: boolean;

        enableEstimatedTime: boolean;
        currency: string;
        canCreateProject: boolean;
        organizationBillableRate: number | null;

        createTimeEntry: (
            entry: Omit<TimeEntry, 'id' | 'organization_id' | 'user_id'>
        ) => Promise<void>;
        updateTimeEntry: (entry: TimeEntry) => Promise<void>;
        deleteTimeEntry: (timeEntryId: string) => Promise<void>;
        createProject: (project: CreateProjectBody) => Promise<Project | undefined>;
        createClient: (client: CreateClientBody) => Promise<Client | undefined>;
        createTag: (name: string) => Promise<Tag | undefined>;

        scheduledCalendarEvents: OrgCalendarEvent[];
        currentUserId?: string | null;
        /** Create/update/delete calendar events (no-op permissions handled upstream). */
        createOrgCalendarEvent: (body: CreateOrgCalendarEventBody) => Promise<void>;
        updateOrgCalendarEvent: (id: string, body: Record<string, unknown>) => Promise<void>;
        deleteOrgCalendarEvent: (id: string) => Promise<void>;
        canCreateCalendarEvents?: boolean;
    }>(),
    {
        currentUserId: null,
        canCreateCalendarEvents: false,
    }
);

const newEventStart = ref<Dayjs | null>(null);
const newEventEnd = ref<Dayjs | null>(null);
const showCreateTimeEntryModal = ref<boolean>(false);
const showEditTimeEntryModal = ref<boolean>(false);
const selectedTimeEntry = ref<TimeEntry | null>(null);
const contextMenuOpen = ref(false);

const showCreateCalendarEventModal = ref(false);
const showEditCalendarEventModal = ref(false);
const showCalendarEventDetailModal = ref(false);
const selectedOrgCalendarEvent = ref<OrgCalendarEvent | null>(null);
const newCalendarEventAllDay = ref(false);

const calendarEventDeleteConfirmOpen = ref(false);
const pendingCalendarEventDelete = ref<OrgCalendarEvent | null>(null);

const showLayerTimeEntries = useLocalStorage('solidtime:calendar-show-time-entries', true);
const showLayerScheduledEvents = useLocalStorage('solidtime:calendar-show-scheduled-events', true);

const optimisticCalendarEventOverrides = ref<Map<string, OrgCalendarEvent>>(new Map());

function mergedScheduledCalendarEvents(): OrgCalendarEvent[] {
    const list = props.scheduledCalendarEvents;
    const over = optimisticCalendarEventOverrides.value;
    if (over.size === 0) return list;
    return list.map((ev) => over.get(ev.id) ?? ev);
}

const rootRef = ref<HTMLElement | null>(null);
const scrollerRef = ref<HTMLElement | null>(null);

const calendarSettings = useLocalStorage<CalendarSettings>(
    'solidtime:calendar-settings',
    {
        snapMinutes: 15,
        startHour: 0,
        endHour: 24,
        slotMinutes: 15,
    },
    { mergeDefaults: true }
);

function onSettingsUpdate(newSettings: CalendarSettings) {
    calendarSettings.value = newSettings;
}

const currentTime = ref(getLocalizedDayJs());
let currentTimeInterval: ReturnType<typeof setInterval> | null = null;

const organization = inject<ComputedRef<Organization>>('organization');

const orgTimeFormat = computed(
    () => organization?.value?.time_format ?? ('24-hours' as const)
);

function projectForCalendarEvent(ev: OrgCalendarEvent) {
    if (!ev.project_id) {
        return undefined;
    }
    return props.projects.find((p) => p.id === ev.project_id);
}

function taskForCalendarEvent(ev: OrgCalendarEvent) {
    if (!ev.task_id) {
        return undefined;
    }
    return props.tasks.find((t) => t.id === ev.task_id);
}

function openCalendarEventDetail(ev: OrgCalendarEvent) {
    selectedOrgCalendarEvent.value = ev;
    showCalendarEventDetailModal.value = true;
}

/** Same ownership rule as context-menu delete: only your events are draggable/resizable. */
function canMutateScheduledCalendarEvent(ev: OrgCalendarEvent): boolean {
    return Boolean(ev.user_id && ev.user_id === props.currentUserId);
}

function onCalendarEventDetailEdit(ev: OrgCalendarEvent) {
    selectedOrgCalendarEvent.value = ev;
    showEditCalendarEventModal.value = true;
}

async function onCalendarEventDetailDelete(ev: OrgCalendarEvent) {
    await props.deleteOrgCalendarEvent(ev.id);
    showCalendarEventDetailModal.value = false;
    selectedOrgCalendarEvent.value = null;
    emit('refresh');
}

watch(showCalendarEventDetailModal, (open) => {
    if (!open && !showEditCalendarEventModal.value) {
        selectedOrgCalendarEvent.value = null;
    }
});

const {
    slots,
    totalGridHeight,
    formatSlotLabel,
    minutesToPixels,
    pixelsToMinutesFromMidnight,
    timeToMinutesFromMidnight,
    getDayFromClientX,
    clientYToGridPixels,
} = useCalendarGrid(calendarSettings, organization, scrollerRef, rootRef);

const {
    activeView,
    viewDays,
    viewTitle,
    emitDatesChange,
    handlePrev,
    handleNext,
    handleToday,
    handleChangeView,
} = useCalendarNavigation({
    onDatesChange: (payload) => emit('dates-change', payload),
    scrollToCurrentTime: () => scrollToCurrentTime(),
});

const cssBackground = useCssVariable('--color-bg-background');

const { optimisticOverrides, calendarEvents, eventsByDay, laneSegmentsByDay, dailyTotals, isToday, nowIndicatorTop } =
    useCalendarEvents({
        timeEntries: () => props.timeEntries,
        scheduledCalendarEvents: () => mergedScheduledCalendarEvents(),
        showTimeEntries: () => showLayerTimeEntries.value,
        showScheduledEvents: () => showLayerScheduledEvents.value,
        projects: () => props.projects,
        clients: () => props.clients,
        tasks: () => props.tasks,
        calendarSettings,
        viewDays,
        currentTime,
        cssBackground,
        minutesToPixels,
        timeToMinutesFromMidnight,
    });

const {
    activityBoxesForDay,
    dayHasActivityStatus,
    getActivityBoxLabel,
    getActivityBoxActivities,
    getActivityPercentage,
    getActivityText,
    getTopActivity,
} = useActivityBoxes({
    activityPeriods: () => props.activityPeriods,
    viewDays,
    calendarSettings,
    minutesToPixels,
});

const { isDragging, dragEventId, dragPreviewsByDay, onEventPointerDown } = useEventDrag({
    calendarSettings,
    viewDays,
    optimisticOverrides,
    updateTimeEntry: (entry) => props.updateTimeEntry(entry),
    emitRefresh: () => emit('refresh'),
    minutesToPixels,
    pixelsToMinutesFromMidnight,
    getDayFromClientX,
    clientYToGridPixels,
    updateOrgCalendarEvent: (id, body) => props.updateOrgCalendarEvent(id, body),
    canMutateScheduledEvent: canMutateScheduledCalendarEvent,
    optimisticCalendarEventOverrides,
    onClickEvent: (ev) => {
        if (ev.kind === 'time_entry') {
            selectedTimeEntry.value = ev.timeEntry;
            showEditTimeEntryModal.value = true;
        } else {
            openCalendarEventDetail(ev.calendarEvent);
        }
    },
});

const {
    isResizing,
    resizeEventId,
    resizeCurrentTop,
    resizeCurrentHeight,
    resizeCrossDayPreviewsByDay,
    resizeLiveDurationSeconds,
    getResizeOriginalDayStr,
    onResizerPointerDown,
} = useEventResize({
    calendarSettings,
    viewDays,
    eventsByDay,
    optimisticOverrides,
    updateTimeEntry: (entry) => props.updateTimeEntry(entry),
    emitRefresh: () => emit('refresh'),
    minutesToPixels,
    pixelsToMinutesFromMidnight,
    getDayFromClientX,
    clientYToGridPixels,
    updateOrgCalendarEvent: (id, body) => props.updateOrgCalendarEvent(id, body),
    canMutateScheduledEvent: canMutateScheduledCalendarEvent,
    optimisticCalendarEventOverrides,
});

const {
    isSelecting,
    selectionDay,
    selectionTop,
    selectionHeight,
    selectionEndDay,
    selectionEndTop,
    selectionEndHeight,
    selectionIntermediateDays,
    onSlotPointerDown,
    clearSelection,
} = useSlotSelection({
    calendarSettings,
    viewDays,
    totalGridHeight,
    pixelsToMinutesFromMidnight,
    getDayFromClientX,
    clientYToGridPixels,
    onSelectionComplete: (start, end) => {
        newEventStart.value = start;
        newEventEnd.value = end;
        showCreateTimeEntryModal.value = true;
    },
});

const {
    contextMenuTimeEntry,
    contextMenuCalendarEvent,
    handleCalendarContextMenu,
    handleContextEditTimeEntry,
    handleContextEditCalendarEvent,
    handleContextDuplicate,
    handleContextDeleteTimeEntry,
    handleContextSplit,
    handleContextStop,
    handleContextDiscard,
    handleContextCreateTimeEntry,
    handleContextCreateCalendarEvent,
} = useContextMenu({
    calendarSettings,
    calendarEvents,
    scheduledCalendarEvents: () => mergedScheduledCalendarEvents(),
    pixelsToMinutesFromMidnight,
    getDayFromClientX,
    clientYToGridPixels,
    createTimeEntry: (entry) => props.createTimeEntry(entry),
    updateTimeEntry: (entry) => props.updateTimeEntry(entry),
    deleteTimeEntry: (id) => props.deleteTimeEntry(id),
    deleteCalendarEvent: (id) => props.deleteOrgCalendarEvent(id),
    onEditTimeEntry: (entry) => {
        selectedTimeEntry.value = entry;
        showEditTimeEntryModal.value = true;
    },
    onEditCalendarEvent: (ev) => {
        selectedOrgCalendarEvent.value = ev;
        showEditCalendarEventModal.value = true;
    },
    onCreateTimeEntryRange: (start, end) => {
        newEventStart.value = start;
        newEventEnd.value = end;
        showCreateTimeEntryModal.value = true;
    },
    onCreateCalendarEventRange: (start, end, allDay) => {
        newEventStart.value = start;
        newEventEnd.value = end;
        newCalendarEventAllDay.value = allDay;
        showCreateCalendarEventModal.value = true;
    },
    canCreateCalendarEvent: () => Boolean(props.canCreateCalendarEvents),
    canDeleteCalendarEvent: (ev) => Boolean(ev.user_id && ev.user_id === props.currentUserId),
    emitRefresh: () => emit('refresh'),
});

function requestContextDeleteCalendarEvent() {
    const ev = contextMenuCalendarEvent.value;
    if (!ev) {
        return;
    }
    pendingCalendarEventDelete.value = ev;
    nextTick(() => {
        calendarEventDeleteConfirmOpen.value = true;
    });
}

async function confirmPendingCalendarEventDelete() {
    const ev = pendingCalendarEventDelete.value;
    if (!ev) {
        return;
    }
    await props.deleteOrgCalendarEvent(ev.id);
    calendarEventDeleteConfirmOpen.value = false;
    pendingCalendarEventDelete.value = null;
    emit('refresh');
}

watch(calendarEventDeleteConfirmOpen, (open) => {
    if (!open) {
        pendingCalendarEventDelete.value = null;
    }
});

watch(showCreateTimeEntryModal, (value) => {
    if (!value) {
        newEventStart.value = null;
        newEventEnd.value = null;
        clearSelection();
        emit('refresh');
    }
});

watch(showEditTimeEntryModal, (value) => {
    if (!value) {
        selectedTimeEntry.value = null;
        emit('refresh');
    }
});

/**
 * Guards slot pointer-down so that clicks which dismiss an open Reka UI
 * layer (context menu, popover, dialog) don't simultaneously start a
 * new time-entry selection on the calendar grid.
 *
 * Because Reka's DismissableLayer registers its document-level
 * `pointerdown` listener *without* capture, it fires AFTER the
 * calendar grid's own handler. That means when this guard runs,
 * `contextMenuOpen` (and modal refs) still reflect the *open* state.
 */
function guardedSlotPointerDown(e: PointerEvent) {
    if (contextMenuOpen.value) return;
        if (
        showCreateTimeEntryModal.value ||
        showEditTimeEntryModal.value ||
        showCreateCalendarEventModal.value ||
        showEditCalendarEventModal.value ||
        showCalendarEventDetailModal.value ||
        calendarEventDeleteConfirmOpen.value
    )
        return;
    onSlotPointerDown(e);
}

function onGridEventPointerDown(e: PointerEvent, dayEvent: DayEvent) {
    if (
        dayEvent.event.kind === 'scheduled_event' &&
        !canMutateScheduledCalendarEvent(dayEvent.event.calendarEvent)
    ) {
        if (e.button !== 0) return;
        const startX = e.clientX;
        const startY = e.clientY;
        function onUp(up: PointerEvent) {
            document.removeEventListener('pointerup', onUp);
            const dx = up.clientX - startX;
            const dy = up.clientY - startY;
            if (Math.sqrt(dx * dx + dy * dy) < DRAG_THRESHOLD) {
                const sev = dayEvent.event;
                if (sev.kind === 'scheduled_event') {
                    openCalendarEventDetail(sev.calendarEvent);
                }
            }
        }
        document.addEventListener('pointerup', onUp);
        return;
    }
    onEventPointerDown(e, dayEvent.event, dayEvent);
}

const scrollToCurrentTime = () => {
    nextTick(() => {
        if (!scrollerRef.value) return;
        const now = getLocalizedDayJs();
        const oneHourBefore = now.subtract(1, 'hour');
        const s = calendarSettings.value;
        const startMin = s.startHour * 60;

        const targetMinutes = now.isSame(oneHourBefore, 'day')
            ? oneHourBefore.hour() * 60 + oneHourBefore.minute()
            : now.hour() * 60 + now.minute();

        const scrollTop = minutesToPixels(Math.max(0, targetMinutes - startMin));
        scrollerRef.value.scrollTop = scrollTop;
    });
};

watch(
    () => props.timeEntries,
    () => {
        if (optimisticOverrides.value.size > 0) {
            optimisticOverrides.value = new Map();
        }
    }
);

watch(
    () => props.scheduledCalendarEvents,
    () => {
        if (optimisticCalendarEventOverrides.value.size > 0) {
            optimisticCalendarEventOverrides.value = new Map();
        }
    }
);

watch(
    calendarSettings,
    () => {
        emitDatesChange();
    },
    { deep: true }
);

let hasScrolledOnLoad = false;

watch(
    () => props.loading,
    (loading) => {
        if (!loading && !hasScrolledOnLoad) {
            hasScrolledOnLoad = true;
            scrollToCurrentTime();
        }
    }
);

onMounted(() => {
    scrollToCurrentTime();
    emitDatesChange();
    currentTimeInterval = setInterval(() => {
        currentTime.value = getLocalizedDayJs();
    }, 60000);
});

onActivated(() => {
    scrollToCurrentTime();
});

onDeactivated(() => {
    contextMenuOpen.value = false;
});

onUnmounted(() => {
    if (currentTimeInterval) {
        clearInterval(currentTimeInterval);
        currentTimeInterval = null;
    }
});

function getEventStyle(dayEvent: DayEvent, dayStr: string): Record<string, string> {
    const ev = dayEvent.event;
    const isResizeTarget = resizeEventId.value === ev.id;

    let top = dayEvent.top;
    let height = dayEvent.height;
    const left = dayEvent.left;
    const width = dayEvent.width;
    let zIndex = '1';

    if (isResizeTarget) {
        const isOnResizeOriginDay = dayStr === getResizeOriginalDayStr();
        if (isOnResizeOriginDay) {
            top = resizeCurrentTop.value;
            height = resizeCurrentHeight.value;
            zIndex = '100';
        }
    }

    return {
        position: 'absolute',
        top: `${top}px`,
        height: `${height}px`,
        left,
        width,
        backgroundColor: ev.backgroundColor,
        borderColor: ev.borderColor,
        zIndex,
    };
}

function getEventOpacityClass(dayEvent: DayEvent, dayStr: string): string {
    const ev = dayEvent.event;
    const isDragTarget = isDragging.value && dragEventId.value === ev.id;
    const isResizeTarget = resizeEventId.value === ev.id;

    if (isDragTarget) return 'opacity-30';

    if (isResizeTarget) {
        const isOnResizeOriginDay = dayStr === getResizeOriginalDayStr();
        if (!isOnResizeOriginDay) return 'opacity-50';
        return 'opacity-100';
    }

    return 'opacity-90 hover:opacity-100';
}

function getEventDurationSeconds(dayEvent: DayEvent, dayStr: string): number {
    const ev = dayEvent.event;
    const isResizeTarget = resizeEventId.value === ev.id;

    if (
        isResizeTarget &&
        dayStr === getResizeOriginalDayStr() &&
        resizeLiveDurationSeconds.value !== null
    ) {
        return resizeLiveDurationSeconds.value;
    }

    return ev.durationMinutes * 60;
}
</script>

<template>
    <div class="w-full relative h-full flex-1 flex flex-col overflow-hidden min-h-0">
        <div v-if="loading" class="flex items-center justify-center h-full">
            <div class="flex flex-col items-center space-y-4">
                <LoadingSpinner class="h-8 w-8" />
                <p class="text-muted-foreground">Loading calendar data...</p>
            </div>
        </div>

        <TimeEntryCreateModal
            v-model:show="showCreateTimeEntryModal"
            :enable-estimated-time="enableEstimatedTime"
            :create-time-entry="createTimeEntry"
            :create-client="createClient"
            :create-project="createProject"
            :create-tag="createTag"
            :currency="currency"
            :can-create-project="canCreateProject"
            :organization-billable-rate="organizationBillableRate"
            :tags="tags as any"
            :projects="projects"
            :tasks="tasks"
            :clients="clients"
            :start="newEventStart ? newEventStart.toISOString() : undefined"
            :end="newEventEnd ? newEventEnd.toISOString() : undefined" />

        <TimeEntryEditModal
            v-model:show="showEditTimeEntryModal"
            :time-entry="selectedTimeEntry as any"
            :enable-estimated-time="enableEstimatedTime"
            :update-time-entry="updateTimeEntry"
            :delete-time-entry="deleteTimeEntry"
            :create-client="createClient"
            :create-project="createProject"
            :create-tag="createTag"
            :tags="tags as any"
            :projects="projects"
            :tasks="tasks"
            :clients="clients"
            :currency="currency"
            :can-create-project="canCreateProject"
            :organization-billable-rate="organizationBillableRate" />

        <CalendarEventFormModal
            v-model:show="showCreateCalendarEventModal"
            :projects="projects"
            :tasks="tasks"
            :initial-starts-at="newEventStart ? newEventStart.toISOString() : null"
            :initial-ends-at="newEventEnd ? newEventEnd.toISOString() : null"
            :initial-all-day="newCalendarEventAllDay"
            @save-create="
                async (body) => {
                    await createOrgCalendarEvent(body);
                    emit('refresh');
                }
            " />

        <CalendarEventFormModal
            v-model:show="showEditCalendarEventModal"
            :projects="projects"
            :tasks="tasks"
            :editing="selectedOrgCalendarEvent"
            @save-update="
                async ({ id, body }) => {
                    await updateOrgCalendarEvent(id, body);
                    emit('refresh');
                }
            " />

        <CalendarEventDetailModal
            v-if="selectedOrgCalendarEvent"
            v-model:show="showCalendarEventDetailModal"
            :calendar-event="selectedOrgCalendarEvent"
            :project="projectForCalendarEvent(selectedOrgCalendarEvent)"
            :task="taskForCalendarEvent(selectedOrgCalendarEvent)"
            :org-time-format="orgTimeFormat"
            @edit="onCalendarEventDetailEdit"
            @delete="onCalendarEventDetailDelete" />

        <AlertDialog v-model:open="calendarEventDeleteConfirmOpen">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete this event?</AlertDialogTitle>
                    <AlertDialogDescription>
                        <template v-if="pendingCalendarEventDelete">
                            “{{ pendingCalendarEventDelete.title }}” will be permanently removed. This
                            cannot be undone.
                        </template>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        :class="buttonVariants({ variant: 'destructive' })"
                        data-testid="calendar_context_delete_confirm"
                        @click="confirmPendingCalendarEventDelete">
                        Delete event
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <template v-if="!loading">
            <CalendarToolbar
                :view-title="viewTitle"
                :active-view="activeView"
                :settings="calendarSettings"
                :show-time-entries="showLayerTimeEntries"
                :show-scheduled-events="showLayerScheduledEvents"
                @prev="handlePrev"
                @next="handleNext"
                @today="handleToday"
                @change-view="handleChangeView"
                @update:settings="onSettingsUpdate"
                @update:show-time-entries="showLayerTimeEntries = $event"
                @update:show-scheduled-events="showLayerScheduledEvents = $event" />

            <ContextMenu v-model:open="contextMenuOpen">
                <ContextMenuTrigger
                    as="div"
                    class="flex-1 min-h-0"
                    @contextmenu="handleCalendarContextMenu">
                    <div
                        ref="rootRef"
                        class="fc h-full flex flex-col bg-default-background text-foreground font-inherit border border-border border-l-transparent overflow-hidden">
                        <div
                            class="fc-header-scroll flex border-b border-border shrink-0 sticky top-0 z-10 bg-default-background">
                            <div
                                class="shrink-0 bg-default-background border-r border-border"
                                :style="{
                                    width: TIME_AXIS_WIDTH + 'px',
                                    minWidth: TIME_AXIS_WIDTH + 'px',
                                }"></div>
                            <div
                                class="grid flex-1 min-w-0"
                                :style="{
                                    gridTemplateColumns: dayColumnGridTemplate(viewDays.length),
                                }">
                                <div
                                    v-for="day in viewDays"
                                    :key="day.format('YYYY-MM-DD')"
                                    class="fc-col-header-cell min-w-0 overflow-hidden border-r border-border px-2 py-3 bg-default-background text-center"
                                    :class="{
                                        'bg-secondary': isToday(day),
                                        'fc-day-today': isToday(day),
                                    }"
                                    :data-date="day.format('YYYY-MM-DD')">
                                    <FullCalendarDayHeader
                                        :date="day"
                                        :is-today="isToday(day)"
                                        :total-seconds="
                                            dailyTotals[day.format('YYYY-MM-DD')] || 0
                                        " />
                                </div>
                            </div>
                        </div>

                        <!-- Outside the time scroll area so all-day events stay visible while scrolling -->
                        <div
                            v-if="showLayerScheduledEvents"
                            class="flex shrink-0 border-b border-border bg-secondary/25 dark:bg-secondary/40">
                            <div
                                class="shrink-0 border-r border-border bg-default-background flex items-center justify-center text-[0.65rem] font-semibold text-text-secondary uppercase tracking-wide px-1"
                                :style="{
                                    width: TIME_AXIS_WIDTH + 'px',
                                    minWidth: TIME_AXIS_WIDTH + 'px',
                                }">
                                all-day
                            </div>
                            <div
                                class="grid flex-1 min-w-0 bg-default-background"
                                :style="{
                                    gridTemplateColumns: dayColumnGridTemplate(viewDays.length),
                                }">
                                <div
                                    v-for="day in viewDays"
                                    :key="'lane-' + day.format('YYYY-MM-DD')"
                                    class="min-w-0 overflow-hidden border-r border-border px-1 py-1.5 space-y-1 min-h-[36px]">
                                    <div
                                        v-for="seg in laneSegmentsByDay[day.format('YYYY-MM-DD')] ||
                                        []"
                                        :key="seg.segmentKey"
                                        :data-event-id="seg.calendarEvent.id"
                                        class="min-w-0 max-w-full truncate rounded-md px-1.5 py-1 text-[0.75rem] leading-tight font-semibold cursor-pointer border shadow-sm box-border"
                                        :style="{
                                            backgroundColor: seg.backgroundColor,
                                            borderColor: seg.borderColor,
                                        }"
                                        @click.stop="openCalendarEventDetail(seg.calendarEvent)">
                                        {{ seg.title }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div ref="scrollerRef" class="fc-scroller flex flex-col flex-1 min-h-0 overflow-y-auto overflow-x-hidden">
                            <div class="flex min-w-0 flex-1 min-h-0">
                                <div
                                    class="shrink-0 bg-default-background border-r border-border"
                                    :style="{
                                        width: TIME_AXIS_WIDTH + 'px',
                                        minWidth: TIME_AXIS_WIDTH + 'px',
                                    }">
                                    <div
                                        v-for="slot in slots"
                                        :key="slot.time"
                                        class="fc-timegrid-slot fc-timegrid-slot-label relative text-right border-t border-border pr-1.5 pt-2 box-border"
                                        :class="{
                                            'fc-timegrid-slot-minor border-t-transparent':
                                                !slot.isHour,
                                        }"
                                        :data-time="slot.time"
                                        :style="{ height: SLOT_HEIGHT + 'px' }">
                                        <span
                                            v-if="slot.isHour"
                                            class="fc-timegrid-slot-label-cushion text-[0.8125rem] text-muted-foreground leading-none block font-light">
                                            {{ formatSlotLabel(slot.minutes / 60) }}
                                        </span>
                                    </div>
                                </div>

                                <div
                                    class="flex-1 min-w-0 relative"
                                    @pointerdown="guardedSlotPointerDown($event)">
                                    <div
                                        class="bg-default-background relative"
                                        :style="{ height: totalGridHeight + 'px' }">
                                        <div
                                            class="absolute inset-0 grid min-w-0"
                                            :style="{
                                                gridTemplateColumns:
                                                    dayColumnGridTemplate(viewDays.length),
                                            }">
                                            <div
                                                v-for="day in viewDays"
                                                :key="'bg-' + day.format('YYYY-MM-DD')"
                                                class="min-w-0"
                                                :style="
                                                    isToday(day)
                                                        ? {
                                                              backgroundColor:
                                                                  'var(--theme-color-default-background)',
                                                          }
                                                        : undefined
                                                " />
                                        </div>
                                        <div
                                            v-for="slot in slots"
                                            :key="'lane-' + slot.time"
                                            class="fc-timegrid-slot fc-timegrid-slot-lane border-t border-border box-border relative"
                                            :class="{
                                                'fc-timegrid-slot-minor border-dotted':
                                                    !slot.isHour,
                                            }"
                                            :data-time="slot.time"
                                            :style="{ height: SLOT_HEIGHT + 'px' }"></div>
                                    </div>
                                    <div
                                        class="grid absolute inset-0 pointer-events-none min-w-0"
                                        :style="{
                                            gridTemplateColumns:
                                                dayColumnGridTemplate(viewDays.length),
                                        }">
                                        <CalendarDayColumn
                                            v-for="day in viewDays"
                                            :key="day.format('YYYY-MM-DD')"
                                            :day-str="day.format('YYYY-MM-DD')"
                                            :total-grid-height="totalGridHeight"
                                            :has-activity-status="
                                                dayHasActivityStatus(day.format('YYYY-MM-DD'))
                                            "
                                            :day-events="
                                                eventsByDay[day.format('YYYY-MM-DD')] || []
                                            "
                                            :get-event-style="getEventStyle"
                                            :get-event-opacity-class="getEventOpacityClass"
                                            :get-event-duration-seconds="getEventDurationSeconds"
                                            :is-dragging="isDragging"
                                            :drag-event-id="dragEventId"
                                            :drag-preview="
                                                dragPreviewsByDay[day.format('YYYY-MM-DD')]
                                            "
                                            :resize-event-id="resizeEventId"
                                            :resize-cross-day-preview="
                                                isResizing
                                                    ? resizeCrossDayPreviewsByDay[
                                                          day.format('YYYY-MM-DD')
                                                      ]
                                                    : undefined
                                            "
                                            :show-now-indicator="
                                                isToday(day) && nowIndicatorTop >= 0
                                            "
                                            :now-indicator-top="nowIndicatorTop"
                                            :activity-boxes="
                                                activityBoxesForDay(day.format('YYYY-MM-DD'))
                                            "
                                            :get-activity-box-label="getActivityBoxLabel"
                                            :get-activity-box-activities="getActivityBoxActivities"
                                            :get-activity-percentage="getActivityPercentage"
                                            :get-activity-text="getActivityText"
                                            :get-top-activity="getTopActivity"
                                            :is-day-view="activeView === 'timeGridDay'"
                                            :show-selection="
                                                isSelecting ||
                                                showCreateTimeEntryModal ||
                                                showCreateCalendarEventModal
                                            "
                                            :is-selection-start="
                                                selectionDay === day.format('YYYY-MM-DD')
                                            "
                                            :is-selection-intermediate="
                                                selectionIntermediateDays.has(
                                                    day.format('YYYY-MM-DD')
                                                )
                                            "
                                            :is-selection-end="
                                                selectionEndDay === day.format('YYYY-MM-DD')
                                            "
                                            :selection-top="selectionTop"
                                            :selection-height="selectionHeight"
                                            :selection-end-top="selectionEndTop"
                                            :selection-end-height="selectionEndHeight"
                                            :can-mutate-scheduled-calendar-event="
                                                canMutateScheduledCalendarEvent
                                            "
                                            @activity-pointerdown="guardedSlotPointerDown"
                                            @event-pointerdown="
                                                (e, dayEvent) => onGridEventPointerDown(e, dayEvent)
                                            "
                                            @event-keydown-enter="
                                                (dayEvent) => {
                                                    if (dayEvent.event.kind === 'time_entry') {
                                                        selectedTimeEntry = dayEvent.event.timeEntry;
                                                        showEditTimeEntryModal = true;
                                                    } else {
                                                        openCalendarEventDetail(
                                                            dayEvent.event.calendarEvent
                                                        );
                                                    }
                                                }
                                            "
                                            @resizer-pointerdown="
                                                (e, dayEvent, edge) =>
                                                    onResizerPointerDown(
                                                        e,
                                                        dayEvent.event,
                                                        dayEvent,
                                                        edge,
                                                        day.format('YYYY-MM-DD')
                                                    )
                                            " />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </ContextMenuTrigger>

                <ContextMenuContent class="min-w-[200px]">
                    <template v-if="contextMenuTimeEntry && contextMenuTimeEntry.end !== null">
                        <ContextMenuItem class="space-x-3" @select="handleContextEditTimeEntry()">
                            <PencilIcon class="w-4 h-4 text-icon-default" />
                            <span>Edit</span>
                        </ContextMenuItem>
                        <ContextMenuItem class="space-x-3" @select="handleContextDuplicate()">
                            <DocumentDuplicateIcon class="w-4 h-4 text-icon-default" />
                            <span>Duplicate</span>
                        </ContextMenuItem>
                        <ContextMenuItem class="space-x-3" @select="handleContextSplit()">
                            <ScissorsIcon class="w-4 h-4 text-icon-default" />
                            <span>Split</span>
                        </ContextMenuItem>
                        <ContextMenuSeparator />
                        <ContextMenuItem
                            class="space-x-3 text-destructive"
                            @select="handleContextDeleteTimeEntry()">
                            <TrashIcon class="w-4 h-4 text-icon-default" />
                            <span>Delete</span>
                        </ContextMenuItem>
                    </template>
                    <template v-else-if="contextMenuTimeEntry && contextMenuTimeEntry.end === null">
                        <ContextMenuItem class="space-x-3" @select="handleContextStop()">
                            <StopIcon class="w-4 h-4 text-icon-default" />
                            <span>Stop</span>
                        </ContextMenuItem>
                        <ContextMenuSeparator />
                        <ContextMenuItem
                            class="space-x-3 text-destructive"
                            @select="handleContextDiscard()">
                            <XMarkIcon class="w-4 h-4 text-icon-default" />
                            <span>Discard</span>
                        </ContextMenuItem>
                    </template>
                    <template v-else-if="contextMenuCalendarEvent">
                        <ContextMenuItem class="space-x-3" @select="handleContextEditCalendarEvent()">
                            <PencilIcon class="w-4 h-4 text-icon-default" />
                            <span>Edit</span>
                        </ContextMenuItem>
                        <ContextMenuItem
                            v-if="
                                contextMenuCalendarEvent.user_id &&
                                contextMenuCalendarEvent.user_id === currentUserId
                            "
                            class="space-x-3 text-destructive"
                            @select="requestContextDeleteCalendarEvent()">
                            <TrashIcon class="w-4 h-4 text-icon-default" />
                            <span>Delete</span>
                        </ContextMenuItem>
                    </template>
                    <template v-else>
                        <ContextMenuItem class="space-x-3" @select="handleContextCreateTimeEntry()">
                            <PlusIcon class="w-4 h-4 text-icon-default" />
                            <span>Create Time Entry</span>
                        </ContextMenuItem>
                        <ContextMenuItem
                            v-if="canCreateCalendarEvents"
                            class="space-x-3"
                            @select="handleContextCreateCalendarEvent()">
                            <PlusIcon class="w-4 h-4 text-icon-default" />
                            <span>Create event</span>
                        </ContextMenuItem>
                    </template>
                </ContextMenuContent>
            </ContextMenu>
        </template>
    </div>
</template>

<style scoped>
.fc-header-scroll {
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-gutter: stable;
    scrollbar-color: transparent transparent;
}
.fc-header-scroll::-webkit-scrollbar {
    width: 8px;
}
.fc-header-scroll::-webkit-scrollbar-track {
    background: transparent;
}
.fc-header-scroll::-webkit-scrollbar-thumb {
    background-color: transparent;
}

.fc-scroller {
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    scrollbar-width: thin;
    scrollbar-color: var(--muted-foreground) transparent;
    scrollbar-gutter: stable;
}
.fc-scroller::-webkit-scrollbar {
    width: 8px;
}
.fc-scroller::-webkit-scrollbar-track {
    background: transparent;
}
.fc-scroller::-webkit-scrollbar-thumb {
    background-color: var(--muted-foreground);
    border-radius: 4px;
}
.fc-scroller::-webkit-scrollbar-thumb:hover {
    background-color: var(--foreground);
}
</style>

<style>
body.fc-resizing-active,
body.fc-resizing-active * {
    cursor: row-resize !important;
}
</style>
