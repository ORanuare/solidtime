import type { OrgCalendarEvent } from '@/packages/api/src';
import { getDayJsInstance } from '@/packages/ui/src/utils/time';

export function isWorkspaceCalendarEvent(
    ev: Pick<OrgCalendarEvent, 'project_id' | 'task_id'>
): boolean {
    const pid = ev.project_id;
    const tid = ev.task_id;
    return (!pid || pid === '') && (!tid || tid === '');
}

/** Attached to a project row, not to a specific task (excludes workspace-wide events). */
export function isProjectOnlyCalendarEvent(
    ev: Pick<OrgCalendarEvent, 'project_id' | 'task_id'>
): boolean {
    const pid = ev.project_id;
    const tid = ev.task_id;
    if (!pid || pid === '') {
        return false;
    }
    return !tid || tid === '';
}

/**
 * Whether an event belongs on a project detail view: workspace events excluded; project/task
 * must match the project being viewed (and a specific task when {@link taskId} is set).
 */
export function isCalendarEventInProjectDetailScope(
    ev: Pick<OrgCalendarEvent, 'project_id' | 'task_id'>,
    projectId: string,
    taskId?: string | null
): boolean {
    if (taskId) {
        if (!ev.task_id || ev.task_id !== taskId) {
            return false;
        }
        return ev.project_id === projectId;
    }
    if (isWorkspaceCalendarEvent(ev)) {
        return false;
    }
    return ev.project_id === projectId;
}

function hasTaskId(ev: OrgCalendarEvent): boolean {
    return Boolean(ev.task_id && ev.task_id !== '');
}

function isProjectDirectOnProject(ev: OrgCalendarEvent, projectId: string): boolean {
    if (isWorkspaceCalendarEvent(ev) || ev.project_id !== projectId) {
        return false;
    }
    return !hasTaskId(ev);
}

function isTaskOnProject(ev: OrgCalendarEvent, projectId: string): boolean {
    if (isWorkspaceCalendarEvent(ev) || ev.project_id !== projectId) {
        return false;
    }
    return hasTaskId(ev);
}

type Rank = [number, number];

function sortKeyProjectOnly(ev: OrgCalendarEvent, projectId: string): Rank {
    if (isWorkspaceCalendarEvent(ev)) {
        return [2, 0];
    }
    if (isProjectDirectOnProject(ev, projectId)) {
        return [0, 0];
    }
    if (isTaskOnProject(ev, projectId)) {
        return [1, 0];
    }
    return [2, 0];
}

function sortKeyWithTask(ev: OrgCalendarEvent, projectId: string, taskId: string): Rank {
    if (isWorkspaceCalendarEvent(ev)) {
        return [2, 0];
    }
    if (ev.task_id === taskId) {
        return [0, 0];
    }
    if (isProjectDirectOnProject(ev, projectId)) {
        return [1, 0];
    }
    if (ev.project_id === projectId) {
        return [1, 1];
    }
    return [2, 0];
}

function compareRank(ka: Rank, kb: Rank, a: OrgCalendarEvent, b: OrgCalendarEvent): number {
    if (ka[0] !== kb[0]) {
        return ka[0] - kb[0];
    }
    if (ka[1] !== kb[1]) {
        return ka[1] - kb[1];
    }
    return (b.created_at ?? '').localeCompare(a.created_at ?? '');
}

function tierRank(ev: OrgCalendarEvent, projectId: string | undefined, taskId: string | undefined): Rank {
    if (!projectId) {
        return [0, 0];
    }
    if (!taskId) {
        return sortKeyProjectOnly(ev, projectId);
    }
    return sortKeyWithTask(ev, projectId, taskId);
}

/** 0 = happening now, 1 = upcoming, 2 = past */
export function calendarEventFocusBucketIndex(
    ev: OrgCalendarEvent,
    nowMs: number = Date.now()
): 0 | 1 | 2 {
    const d = getDayJsInstance();
    const now = d(nowMs);
    if (ev.all_day && ev.starts_at && ev.ends_at) {
        const startDay = d(ev.starts_at).startOf('day');
        const endDay = d(ev.ends_at).startOf('day');
        const nowDay = now.startOf('day');
        if (!nowDay.isBefore(startDay) && nowDay.isBefore(endDay)) {
            return 0;
        }
        if (nowDay.isBefore(startDay)) {
            return 1;
        }
        return 2;
    }
    if (!ev.starts_at || !ev.ends_at) {
        return 2;
    }
    const s = d(ev.starts_at);
    const e = d(ev.ends_at);
    if ((now.isSame(s) || now.isAfter(s)) && now.isBefore(e)) {
        return 0;
    }
    if (now.isBefore(s)) {
        return 1;
    }
    return 2;
}

export function getCalendarEventFocusBucket(
    ev: OrgCalendarEvent,
    nowMs?: number
): 'active' | 'upcoming' | 'past' {
    const i = calendarEventFocusBucketIndex(ev, nowMs);
    return i === 0 ? 'active' : i === 1 ? 'upcoming' : 'past';
}

/**
 * Active → upcoming → past; within each bucket, same relevance ordering as timer-focus notes.
 */
export function sortCalendarEventsForTimerFocus(
    list: OrgCalendarEvent[],
    projectId: string | undefined,
    taskId: string | undefined,
    nowMs: number = Date.now()
): OrgCalendarEvent[] {
    const d = getDayJsInstance();

    return [...list].sort((a, b) => {
        const ba = calendarEventFocusBucketIndex(a, nowMs);
        const bb = calendarEventFocusBucketIndex(b, nowMs);
        if (ba !== bb) {
            return ba - bb;
        }

        const ta = tierRank(a, projectId, taskId);
        const tb = tierRank(b, projectId, taskId);
        const tie = compareRank(ta, tb, a, b);
        if (tie !== 0) {
            return tie;
        }

        if (ba === 2) {
            return d(b.ends_at!).valueOf() - d(a.ends_at!).valueOf();
        }
        return d(a.starts_at!).valueOf() - d(b.starts_at!).valueOf();
    });
}
