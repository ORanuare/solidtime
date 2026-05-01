import type { TimeEntry, Project, Client, Task, OrgCalendarEvent } from '@/packages/api/src';
import type { Dayjs } from 'dayjs';
import type { ActivityPeriod } from './activityTypes';

export const SLOT_HEIGHT = 25;
export const DRAG_THRESHOLD = 5;
export const TIME_AXIS_WIDTH = 48;

/** Time entry block rendered in the calendar grid (legacy name conflict avoided with API CalendarEvent). */
export interface TimeEntryCalendarBlock {
    kind: 'time_entry';
    id: string;
    timeEntry: TimeEntry;
    project?: Project;
    client?: Client;
    task?: Task;
    isRunning: boolean;
    durationMinutes: number;
    title: string;
    backgroundColor: string;
    borderColor: string;
    dayStart: Dayjs;
    dayEnd: Dayjs;
}

/** Scheduled calendar event shown in the timed grid (single local day, not all-day). */
export interface ScheduledCalendarEventBlock {
    kind: 'scheduled_event';
    id: string;
    calendarEvent: OrgCalendarEvent;
    project?: Project;
    client?: Client;
    task?: Task;
    isRunning: false;
    durationMinutes: number;
    title: string;
    backgroundColor: string;
    borderColor: string;
    dayStart: Dayjs;
    dayEnd: Dayjs;
    allDay: boolean;
}

export type CalendarGridEvent = TimeEntryCalendarBlock | ScheduledCalendarEventBlock;

/** All-day or multi-day timed milestone segment in the top lane. */
export interface AllDayLaneSegment {
    segmentKey: string;
    calendarEvent: OrgCalendarEvent;
    dayStr: string;
    title: string;
    backgroundColor: string;
    borderColor: string;
}

export interface DayEvent {
    event: CalendarGridEvent;
    top: number;
    height: number;
    left: string;
    width: string;
    isClippedStart: boolean;
    isClippedEnd: boolean;
}

export interface ActivityBox {
    dateStr: string;
    top: number;
    height: number;
    isIdle: boolean;
    period: ActivityPeriod;
}
