import type { OrgCalendarEvent, Project, Task } from '@/packages/api/src';

type TaskRef = { id: string; project_id: string; is_done?: boolean };

/** Raw assignment rows from the API (empty = workspace-level event). */
export function eventAssignmentList(
    ev: { assignments?: OrgCalendarEvent['assignments'] }
): NonNullable<OrgCalendarEvent['assignments']> {
    return ev.assignments ?? [];
}

export function isWorkspaceCalendarEvent(
    ev: Pick<OrgCalendarEvent, 'assignments' | 'project_id' | 'task_id'>
): boolean {
    if (eventAssignmentList(ev).length > 0) {
        return false;
    }
    const pid = ev.project_id;
    const tid = ev.task_id;
    return (!pid || pid === '') && (!tid || tid === '');
}

/** Number of project/task rows linked to the event (0 = workspace). */
export function orgCalendarEventAttachmentLinkCount(
    ev: { assignments?: OrgCalendarEvent['assignments'] }
): number {
    return eventAssignmentList(ev).length;
}

/**
 * One-line attachment summary for event lists and compact rows.
 * Uses API `eventable_label` when multiple links (compact scope prefix + names).
 */
export function orgCalendarEventAttachmentSummaryForList(
    ev: OrgCalendarEvent,
    primaryProject?: Project,
    primaryTask?: Task
): string {
    const rows = eventAssignmentList(ev);
    if (rows.length > 1) {
        return ev.eventable_label?.trim() || rows.map((r) => r.name).filter(Boolean).join(', ');
    }
    if (rows.length === 1) {
        const a = rows[0]!;
        if (a.type === 'task') {
            const taskName = primaryTask != null && primaryTask.id === a.id ? primaryTask.name : a.name;
            if (primaryProject?.name) {
                return `${primaryProject.name} › ${taskName}`;
            }
            return taskName;
        }
        return a.name;
    }
    if (primaryTask?.name) {
        return primaryProject?.name
            ? `${primaryProject.name} › ${primaryTask.name}`
            : primaryTask.name;
    }
    if (primaryProject?.name) {
        return primaryProject.name;
    }
    return ev.eventable_label?.trim() || 'Workspace';
}

export function eventTouchesTask(ev: OrgCalendarEvent, taskId: string): boolean {
    return eventAssignmentList(ev).some((a) => a.type === 'task' && a.id === taskId);
}

export function eventTouchesProject(ev: OrgCalendarEvent, projectId: string, tasks: TaskRef[]): boolean {
    for (const a of eventAssignmentList(ev)) {
        if (a.type === 'project' && a.id === projectId) {
            return true;
        }
        if (a.type === 'task') {
            const t = tasks.find((x) => x.id === a.id);
            if (t?.project_id === projectId) {
                return true;
            }
        }
    }
    return ev.project_id === projectId;
}

/** Event is linked to project(s) only — no task assignment rows (excludes workspace). */
export function isProjectOnlyCalendarEvent(ev: OrgCalendarEvent): boolean {
    const rows = eventAssignmentList(ev);
    if (rows.length > 0) {
        return rows.some((a) => a.type === 'project') && !rows.some((a) => a.type === 'task');
    }
    const pid = ev.project_id;
    const tid = ev.task_id;
    if (!pid || pid === '') {
        return false;
    }
    return !tid || tid === '';
}

export function calendarEventAttachmentStripeLevel(
    ev: OrgCalendarEvent
): 'workspace' | 'project' | 'task' {
    if (eventAssignmentList(ev).some((a) => a.type === 'task')) {
        return 'task';
    }
    if (eventAssignmentList(ev).some((a) => a.type === 'project')) {
        return 'project';
    }
    if (ev.task_id) {
        return 'task';
    }
    if (ev.project_id) {
        return 'project';
    }
    return 'workspace';
}

/** Whether the event should appear on a project detail list (optionally scoped to one task). */
export function isCalendarEventInProjectDetailScope(
    ev: OrgCalendarEvent,
    projectId: string,
    taskId?: string | null,
    tasks?: TaskRef[]
): boolean {
    const taskList = tasks ?? [];
    if (taskId) {
        if (!eventTouchesTask(ev, taskId)) {
            return false;
        }
        const t = taskList.find((x) => x.id === taskId);
        return t?.project_id === projectId;
    }
    if (isWorkspaceCalendarEvent(ev)) {
        return false;
    }
    return eventTouchesProject(ev, projectId, taskList);
}
