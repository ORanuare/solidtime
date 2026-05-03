import { computed, ref, type Ref, type ComputedRef } from 'vue';
import chroma from 'chroma-js';
import type { Dayjs } from 'dayjs';
import type { TimeEntry, Project, Client, Task, OrgCalendarEvent } from '@/packages/api/src';
import { getDayJsInstance, getLocalizedDayJs } from '../utils/time';
import type { CalendarSettings } from './calendarSettings';
import type {
    CalendarGridEvent,
    TimeEntryCalendarBlock,
    ScheduledCalendarEventBlock,
    AllDayLaneSegment,
    DayEvent,
} from './calendarTypes';

/** Scheduled events: indigo so they read as “plans” and stay distinct from typical green/teal project blocks. */
const SCHEDULED_EVENT_BASE_COLOR = '#4338ca';

interface PositionedEvent {
    event: CalendarGridEvent;
    startMin: number;
    endMin: number;
    isClippedStart: boolean;
    isClippedEnd: boolean;
}

function scheduledColors(themeBackground: string | undefined): { bg: string; border: string } {
    const base = SCHEDULED_EVENT_BASE_COLOR;
    const bg = chroma.mix(base, themeBackground?.trim() || '#ffffff', 0.78, 'lab').hex();
    const border = chroma.mix(base, themeBackground?.trim() || '#ffffff', 0.28, 'lab').hex();
    return { bg, border };
}

interface ColumnAssignment extends PositionedEvent {
    col: number;
}

/** Clip an event's time range to a single day and the visible hour range. */
function clipEventToDay(
    ev: CalendarGridEvent,
    dayStart: Dayjs,
    dayEnd: Dayjs,
    visibleStartMin: number,
    visibleEndMin: number,
    timeToMinutesFromMidnight: (time: Dayjs) => number
): PositionedEvent {
    const isClippedStart = ev.dayStart.isBefore(dayStart);
    const isClippedEnd = ev.dayEnd.isAfter(dayEnd);

    let evStartMin = isClippedStart ? 0 : timeToMinutesFromMidnight(ev.dayStart);
    let evEndMin = isClippedEnd ? 24 * 60 : timeToMinutesFromMidnight(ev.dayEnd);

    evStartMin = Math.max(evStartMin, visibleStartMin);
    evEndMin = Math.min(evEndMin, visibleEndMin);

    if (evEndMin <= evStartMin) {
        evEndMin = evStartMin + 1;
    }

    return { event: ev, startMin: evStartMin, endMin: evEndMin, isClippedStart, isClippedEnd };
}

function comparePositionedByStartAndDuration(a: PositionedEvent, b: PositionedEvent): number {
    if (a.startMin !== b.startMin) return a.startMin - b.startMin;
    return b.endMin - b.startMin - (a.endMin - a.startMin);
}

/**
 * Greedy column packing: events that overlap in time sit in different columns.
 * Time entries are always placed before scheduled calendar events so when both
 * overlap, the time entry stays left and the scheduled block stays right.
 */
function assignColumnsTimeEntriesLeft(positioned: PositionedEvent[]): ColumnAssignment[] {
    const timeEntries = positioned.filter((p) => p.event.kind === 'time_entry');
    const scheduled = positioned.filter((p) => p.event.kind === 'scheduled_event');
    timeEntries.sort(comparePositionedByStartAndDuration);
    scheduled.sort(comparePositionedByStartAndDuration);

    const columns: PositionedEvent[][] = [];
    const result: ColumnAssignment[] = [];

    function placeItem(item: PositionedEvent) {
        let placed = false;
        for (let c = 0; c < columns.length; c++) {
            const lastInCol = columns[c]![columns[c]!.length - 1]!;
            if (lastInCol.endMin <= item.startMin) {
                columns[c]!.push(item);
                result.push({ ...item, col: c });
                placed = true;
                break;
            }
        }
        if (!placed) {
            columns.push([item]);
            result.push({ ...item, col: columns.length - 1 });
        }
    }

    for (const item of timeEntries) placeItem(item);
    for (const item of scheduled) placeItem(item);

    return result;
}

/** Convert column-assigned groups into pixel-positioned DayEvent objects. */
function groupsToDayEvents(
    groups: { items: ColumnAssignment[]; totalCols: number }[],
    visibleStartMin: number,
    minutesToPixels: (minutes: number) => number
): DayEvent[] {
    const result: DayEvent[] = [];
    for (const group of groups) {
        for (const item of group.items) {
            const top = minutesToPixels(item.startMin - visibleStartMin);
            const height = minutesToPixels(item.endMin - item.startMin);
            result.push({
                event: item.event,
                top,
                height: Math.max(height, 1),
                left: `${(item.col / group.totalCols) * 100}%`,
                width: `${(1 / group.totalCols) * 100}%`,
                isClippedStart: item.isClippedStart,
                isClippedEnd: item.isClippedEnd,
            });
        }
    }
    return result;
}

/** Compute positioned events for a single day. */
function layoutDayEvents(
    dayEvents: CalendarGridEvent[],
    dayStart: Dayjs,
    dayEnd: Dayjs,
    visibleStartMin: number,
    visibleEndMin: number,
    timeToMinutesFromMidnight: (time: Dayjs) => number,
    minutesToPixels: (minutes: number) => number
): DayEvent[] {
    const positioned = dayEvents.map((ev) =>
        clipEventToDay(
            ev,
            dayStart,
            dayEnd,
            visibleStartMin,
            visibleEndMin,
            timeToMinutesFromMidnight
        )
    );

    const eventColumns = assignColumnsTimeEntriesLeft(positioned);
    const groups = groupOverlappingEvents(eventColumns);
    return groupsToDayEvents(groups, visibleStartMin, minutesToPixels);
}

/** Group events that transitively overlap so each group shares column count. */
function groupOverlappingEvents(
    eventColumns: ColumnAssignment[]
): { items: ColumnAssignment[]; totalCols: number }[] {
    const groups: { items: ColumnAssignment[]; totalCols: number }[] = [];
    const assigned = new Set<number>();

    for (let i = 0; i < eventColumns.length; i++) {
        if (assigned.has(i)) continue;

        const group = [eventColumns[i]!];
        assigned.add(i);

        let expanded = true;
        while (expanded) {
            expanded = false;
            for (let j = 0; j < eventColumns.length; j++) {
                if (assigned.has(j)) continue;
                const candidate = eventColumns[j]!;
                for (const member of group) {
                    if (candidate.startMin < member.endMin && candidate.endMin > member.startMin) {
                        group.push(candidate);
                        assigned.add(j);
                        expanded = true;
                        break;
                    }
                }
            }
        }

        let maxCol = 0;
        for (const item of group) {
            if (item.col > maxCol) maxCol = item.col;
        }
        groups.push({ items: group, totalCols: maxCol + 1 });
    }

    return groups;
}

function resolveScheduledAttachments(
    ev: OrgCalendarEvent,
    projects: Project[],
    clients: Client[],
    tasks: Task[]
): { project?: Project; client?: Client; task?: Task } {
    const task = tasks.find((t) => t.id === ev.task_id);
    const project =
        projects.find((p) => p.id === ev.project_id) ||
        (task ? projects.find((p) => p.id === task.project_id) : undefined);
    const client = project ? clients.find((c) => c.id === project.client_id) : undefined;
    return { project, client, task };
}

/** Same calendar day in localized TZ for both instants. */
function isSingleLocalDay(start: Dayjs, end: Dayjs): boolean {
    return start.format('YYYY-MM-DD') === end.format('YYYY-MM-DD');
}

export function useCalendarEvents(params: {
    timeEntries: () => TimeEntry[];
    scheduledCalendarEvents: () => OrgCalendarEvent[];
    showTimeEntries: () => boolean;
    showScheduledEvents: () => boolean;
    projects: () => Project[];
    clients: () => Client[];
    tasks: () => Task[];
    calendarSettings: Ref<CalendarSettings>;
    viewDays: ComputedRef<Dayjs[]>;
    currentTime: Ref<Dayjs>;
    cssBackground: Ref<string>;
    minutesToPixels: (minutes: number) => number;
    timeToMinutesFromMidnight: (time: Dayjs) => number;
}) {
    const optimisticOverrides = ref<Map<string, TimeEntry>>(new Map());

    const laneSegmentsByDay = computed<Record<string, AllDayLaneSegment[]>>(() => {
        const themeBg = params.cssBackground.value?.trim();
        const { bg, border } = scheduledColors(themeBg);
        const result: Record<string, AllDayLaneSegment[]> = {};
        if (!params.showScheduledEvents()) {
            return result;
        }

        for (const day of params.viewDays.value) {
            result[day.format('YYYY-MM-DD')] = [];
        }

        for (const raw of params.scheduledCalendarEvents()) {
            if (!raw.starts_at || !raw.ends_at) continue;
            const startLocal = getLocalizedDayJs(raw.starts_at);
            const endLocal = getLocalizedDayJs(raw.ends_at);
            const multiDayTimed = !raw.all_day && !isSingleLocalDay(startLocal, endLocal);
            const useLane = raw.all_day || multiDayTimed;
            if (!useLane) continue;

            for (const day of params.viewDays.value) {
                const dayStr = day.format('YYYY-MM-DD');
                const dayStart = day.startOf('day');
                const dayEnd = day.endOf('day');
                if (!startLocal.isBefore(dayEnd) || !endLocal.isAfter(dayStart)) continue;

                if (!result[dayStr]) result[dayStr] = [];
                const linkCount = raw.assignments?.length ?? 0;
                result[dayStr]!.push({
                    segmentKey: `${raw.id}-${dayStr}-lane`,
                    calendarEvent: raw,
                    dayStr,
                    title: raw.title,
                    attachmentHint:
                        linkCount > 1 ? raw.eventable_label || undefined : undefined,
                    backgroundColor: bg,
                    borderColor: border,
                });
            }
        }

        return result;
    });

    const calendarEvents = computed<CalendarGridEvent[]>(() => {
        const themeBackground = params.cssBackground.value?.trim();
        const fromEntries: CalendarGridEvent[] = [];

        if (params.showTimeEntries()) {
            for (const rawEntry of params.timeEntries()) {
                const timeEntry = optimisticOverrides.value.get(rawEntry.id) || rawEntry;
                const isRunning = timeEntry.end === null;
                const project = params.projects().find((p) => p.id === timeEntry.project_id);
                const client = params.clients().find((c) => c.id === project?.client_id);
                const task = params.tasks().find((t) => t.id === timeEntry.task_id);

                const effectiveEnd = isRunning
                    ? params.currentTime.value
                    : getDayJsInstance()(timeEntry.end!);
                const durationMinutes = effectiveEnd.diff(
                    getDayJsInstance()(timeEntry.start),
                    'minutes'
                );

                const title = timeEntry.description || 'No description';
                const baseColor = project?.color || '#6B7280';
                const backgroundColor = chroma.mix(baseColor, themeBackground, 0.58, 'lab').hex();
                const borderColor = chroma.mix(baseColor, themeBackground, 0.42, 'lab').hex();

                const startTime = getLocalizedDayJs(timeEntry.start);
                const endTime = isRunning
                    ? getLocalizedDayJs(params.currentTime.value.toISOString())
                    : durationMinutes === 0
                      ? startTime.add(1, 'second')
                      : getLocalizedDayJs(timeEntry.end!);

                const block: TimeEntryCalendarBlock = {
                    kind: 'time_entry',
                    id: timeEntry.id,
                    timeEntry,
                    project,
                    client,
                    task,
                    isRunning,
                    durationMinutes,
                    title,
                    backgroundColor,
                    borderColor,
                    dayStart: startTime,
                    dayEnd: endTime,
                };
                fromEntries.push(block);
            }
        }

        const fromScheduled: ScheduledCalendarEventBlock[] = [];
        if (params.showScheduledEvents()) {
            const { bg, border } = scheduledColors(themeBackground);
            for (const raw of params.scheduledCalendarEvents()) {
                if (!raw.starts_at || !raw.ends_at) continue;
                const startLocal = getLocalizedDayJs(raw.starts_at);
                const endLocal = getLocalizedDayJs(raw.ends_at);
                const multiDayTimed = !raw.all_day && !isSingleLocalDay(startLocal, endLocal);
                if (raw.all_day || multiDayTimed) continue;

                const { project, client, task } = resolveScheduledAttachments(
                    raw,
                    params.projects(),
                    params.clients(),
                    params.tasks()
                );
                const durationMinutes = Math.max(1, endLocal.diff(startLocal, 'minute'));

                fromScheduled.push({
                    kind: 'scheduled_event',
                    id: raw.id,
                    calendarEvent: raw,
                    project,
                    client,
                    task,
                    isRunning: false,
                    durationMinutes,
                    title: raw.title,
                    backgroundColor: bg,
                    borderColor: border,
                    dayStart: startLocal,
                    dayEnd: endLocal,
                    allDay: raw.all_day,
                });
            }
        }

        return [...fromEntries, ...fromScheduled];
    });

    const eventsByDay = computed(() => {
        const s = params.calendarSettings.value;
        const visibleStartMin = s.startHour * 60;
        const visibleEndMin = s.endHour * 60;
        const result: Record<string, DayEvent[]> = {};

        for (const day of params.viewDays.value) {
            const dayStart = day.startOf('day');
            const dayEnd = day.endOf('day');

            const dayEvents = calendarEvents.value.filter(
                (ev) => ev.dayStart.isBefore(dayEnd) && ev.dayEnd.isAfter(dayStart)
            );

            result[day.format('YYYY-MM-DD')] = layoutDayEvents(
                dayEvents,
                dayStart,
                dayEnd,
                visibleStartMin,
                visibleEndMin,
                params.timeToMinutesFromMidnight,
                params.minutesToPixels
            );
        }

        return result;
    });

    const dailyTotals = computed(() => {
        const totals: Record<string, number> = {};
        if (!params.showTimeEntries()) {
            return totals;
        }
        params.timeEntries().forEach((entry) => {
            const date = getLocalizedDayJs(entry.start).format('YYYY-MM-DD');
            let durationSeconds: number;

            if (entry.end !== null) {
                durationSeconds = getDayJsInstance()(entry.end).diff(
                    getDayJsInstance()(entry.start),
                    'seconds'
                );
            } else {
                durationSeconds = params.currentTime.value.diff(
                    getDayJsInstance()(entry.start),
                    'seconds'
                );
            }

            totals[date] = (totals[date] || 0) + durationSeconds;
        });
        return totals;
    });

    function isToday(day: Dayjs): boolean {
        return day.isSame(getLocalizedDayJs(), 'day');
    }

    const nowIndicatorTop = computed(() => {
        const s = params.calendarSettings.value;
        const now = getLocalizedDayJs(params.currentTime.value.toISOString());
        const minutesFromMidnight = now.hour() * 60 + now.minute();
        const startMin = s.startHour * 60;
        if (minutesFromMidnight < startMin || minutesFromMidnight >= s.endHour * 60) return -1;
        return params.minutesToPixels(minutesFromMidnight - startMin);
    });

    return {
        optimisticOverrides,
        calendarEvents,
        eventsByDay,
        laneSegmentsByDay,
        dailyTotals,
        isToday,
        nowIndicatorTop,
    };
}
