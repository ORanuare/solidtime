import { ref, type Ref, type ComputedRef } from 'vue';
import type { Dayjs } from 'dayjs';
import type { TimeEntry, OrgCalendarEvent } from '@/packages/api/src';
import { getDayJsInstance, getLocalizedDayJsFromMinutes } from '../utils/time';

import type { CalendarSettings } from './calendarSettings';
import type { CalendarGridEvent } from './calendarTypes';

export function useContextMenu(params: {
    calendarSettings: Ref<CalendarSettings>;
    calendarEvents: ComputedRef<CalendarGridEvent[]>;
    /** Lane-only scheduled events (all-day / multi-day timed) are omitted from `calendarEvents` but use the same `data-event-id`. */
    scheduledCalendarEvents: () => OrgCalendarEvent[];
    pixelsToMinutesFromMidnight: (px: number) => number;
    getDayFromClientX: (clientX: number) => string | null;
    clientYToGridPixels: (clientY: number) => number;
    createTimeEntry: (
        entry: Omit<TimeEntry, 'id' | 'organization_id' | 'user_id'>
    ) => Promise<void>;
    updateTimeEntry: (entry: TimeEntry) => Promise<void>;
    deleteTimeEntry: (id: string) => Promise<void>;
    deleteCalendarEvent: (id: string) => Promise<void>;
    onEditTimeEntry: (entry: TimeEntry) => void;
    onEditCalendarEvent: (ev: OrgCalendarEvent) => void;
    onCreateTimeEntryRange: (start: Dayjs, end: Dayjs) => void;
    onCreateCalendarEventRange: (start: Dayjs, end: Dayjs, allDay: boolean) => void;
    canCreateCalendarEvent: () => boolean;
    canDeleteCalendarEvent: (ev: OrgCalendarEvent) => boolean;
    emitRefresh: () => void;
}) {
    const contextMenuTimeEntry = ref<TimeEntry | null>(null);
    const contextMenuCalendarEvent = ref<OrgCalendarEvent | null>(null);
    const contextMenuCreateTime = ref<{ start: Dayjs; end: Dayjs } | null>(null);

    function getTimeAtClickPosition(event: MouseEvent): { start: Dayjs; end: Dayjs } | null {
        const date = params.getDayFromClientX(event.clientX);
        if (!date) return null;

        const gridY = params.clientYToGridPixels(event.clientY);
        const minutesFromGridStart = params.pixelsToMinutesFromMidnight(gridY);

        const snap = params.calendarSettings.value.snapMinutes;
        const snappedMinutes = Math.floor(minutesFromGridStart / snap) * snap;

        const startLocal = getLocalizedDayJsFromMinutes(date, snappedMinutes);
        const snappedEnd = getLocalizedDayJsFromMinutes(date, snappedMinutes + snap);

        return { start: startLocal.utc(), end: snappedEnd.utc() };
    }

    function handleCalendarContextMenu(event: MouseEvent) {
        const target = event.target as HTMLElement;
        const eventEl = target.closest<HTMLElement>('[data-event-id]');

        if (!eventEl) {
            contextMenuTimeEntry.value = null;
            contextMenuCalendarEvent.value = null;
            const timeInfo = getTimeAtClickPosition(event);
            contextMenuCreateTime.value = timeInfo;
            return;
        }

        const eventId = eventEl.getAttribute('data-event-id');
        if (!eventId) {
            contextMenuTimeEntry.value = null;
            contextMenuCalendarEvent.value = null;
            contextMenuCreateTime.value = getTimeAtClickPosition(event);
            return;
        }

        const ev = params.calendarEvents.value.find((e) => e.id === eventId);
        if (ev) {
            contextMenuCreateTime.value = null;

            if (ev.kind === 'scheduled_event') {
                contextMenuTimeEntry.value = null;
                contextMenuCalendarEvent.value = ev.calendarEvent;
                return;
            }

            contextMenuCalendarEvent.value = null;
            contextMenuTimeEntry.value = ev.timeEntry;
            return;
        }

        const laneEv = params.scheduledCalendarEvents().find((e) => e.id === eventId);
        if (laneEv) {
            contextMenuCreateTime.value = null;
            contextMenuTimeEntry.value = null;
            contextMenuCalendarEvent.value = laneEv;
            return;
        }

        contextMenuTimeEntry.value = null;
        contextMenuCalendarEvent.value = null;
        contextMenuCreateTime.value = getTimeAtClickPosition(event);
    }

    function handleContextEditTimeEntry() {
        if (!contextMenuTimeEntry.value || contextMenuTimeEntry.value.end === null) return;
        params.onEditTimeEntry(contextMenuTimeEntry.value);
    }

    function handleContextEditCalendarEvent() {
        if (!contextMenuCalendarEvent.value) return;
        params.onEditCalendarEvent(contextMenuCalendarEvent.value);
    }

    async function handleContextDuplicate() {
        if (!contextMenuTimeEntry.value || contextMenuTimeEntry.value.end === null) return;
        const entry = contextMenuTimeEntry.value;
        await params.createTimeEntry({
            start: entry.start,
            end: entry.end,
            billable: entry.billable,
            description: entry.description,
            project_id: entry.project_id,
            task_id: entry.task_id,
            tags: entry.tags,
        });
        params.emitRefresh();
    }

    async function handleContextDeleteTimeEntry() {
        if (!contextMenuTimeEntry.value || contextMenuTimeEntry.value.end === null) return;
        await params.deleteTimeEntry(contextMenuTimeEntry.value.id);
        params.emitRefresh();
    }

    async function handleContextDeleteCalendarEvent() {
        if (!contextMenuCalendarEvent.value) return;
        await params.deleteCalendarEvent(contextMenuCalendarEvent.value.id);
        params.emitRefresh();
    }

    async function handleContextSplit() {
        if (!contextMenuTimeEntry.value || contextMenuTimeEntry.value.end === null) return;
        const entry = contextMenuTimeEntry.value;
        if (!entry.end) return;
        const start = getDayJsInstance()(entry.start);
        const end = getDayJsInstance()(entry.end);
        const midpoint = start.add(end.diff(start) / 2, 'millisecond').startOf('minute');

        try {
            await params.updateTimeEntry({ ...entry, end: midpoint.utc().format() });
        } catch {
            params.emitRefresh();
            return;
        }

        try {
            await params.createTimeEntry({
                start: midpoint.utc().format(),
                end: entry.end,
                billable: entry.billable,
                description: entry.description,
                project_id: entry.project_id,
                task_id: entry.task_id,
                tags: entry.tags,
            });
        } catch {
            try {
                await params.updateTimeEntry({ ...entry });
            } catch {
                //
            }
        }
        params.emitRefresh();
    }

    async function handleContextStop() {
        if (!contextMenuTimeEntry.value || contextMenuTimeEntry.value.end !== null) return;
        const entry = contextMenuTimeEntry.value;
        await params.updateTimeEntry({
            ...entry,
            end: getDayJsInstance()().utc().format(),
        });
        params.emitRefresh();
    }

    async function handleContextDiscard() {
        if (!contextMenuTimeEntry.value || contextMenuTimeEntry.value.end !== null) return;
        await params.deleteTimeEntry(contextMenuTimeEntry.value.id);
        params.emitRefresh();
    }

    function handleContextCreateTimeEntry() {
        if (contextMenuCreateTime.value) {
            params.onCreateTimeEntryRange(
                contextMenuCreateTime.value.start,
                contextMenuCreateTime.value.end
            );
        } else {
            params.onCreateTimeEntryRange(
                getDayJsInstance()().utc(),
                getDayJsInstance()().utc().add(1, 'hour')
            );
        }
    }

    function handleContextCreateCalendarEvent() {
        if (!params.canCreateCalendarEvent()) return;
        if (contextMenuCreateTime.value) {
            params.onCreateCalendarEventRange(
                contextMenuCreateTime.value.start,
                contextMenuCreateTime.value.end,
                false
            );
        } else {
            params.onCreateCalendarEventRange(
                getDayJsInstance()().utc(),
                getDayJsInstance()().utc().add(1, 'hour'),
                false
            );
        }
    }

    return {
        contextMenuTimeEntry,
        contextMenuCalendarEvent,
        contextMenuCreateTime,
        handleCalendarContextMenu,
        handleContextEditTimeEntry,
        handleContextEditCalendarEvent,
        handleContextDuplicate,
        handleContextDeleteTimeEntry,
        handleContextDeleteCalendarEvent,
        handleContextSplit,
        handleContextStop,
        handleContextDiscard,
        handleContextCreateTimeEntry,
        handleContextCreateCalendarEvent,
    };
}
