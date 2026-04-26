import type { Note } from '@/packages/api/src';

function isWorkspaceNote(n: Note): boolean {
    if (n.notable_label === 'Workspace') {
        return true;
    }
    if (n.notable_type == null && n.notable_id == null) {
        return true;
    }
    return false;
}

function hasTaskId(n: Note): boolean {
    return Boolean(n.task_id && n.task_id !== '');
}

function isProjectDirectOnProject(n: Note, projectId: string): boolean {
    if (isWorkspaceNote(n) || n.project_id !== projectId) {
        return false;
    }
    return !hasTaskId(n);
}

function isTaskOnProject(n: Note, projectId: string): boolean {
    if (isWorkspaceNote(n) || n.project_id !== projectId) {
        return false;
    }
    return hasTaskId(n);
}

/**
 * project-only timer: project notes → task notes in project → workspace
 * task on timer: this task → project / other in-project (non-ws) → workspace
 */
export function sortNotesForTimerFocus(
    list: Note[],
    projectId: string | undefined,
    taskId: string | undefined
): Note[] {
    if (!projectId) {
        return [...list].sort(compareByCreatedAtDesc);
    }
    if (!taskId) {
        return [...list].sort((a, b) => {
            const ka = sortKeyProjectOnly(a, projectId);
            const kb = sortKeyProjectOnly(b, projectId);
            return compareRank(ka, kb, a, b);
        });
    }
    return [...list].sort((a, b) => {
        const ka = sortKeyWithTask(a, projectId, taskId);
        const kb = sortKeyWithTask(b, projectId, taskId);
        return compareRank(ka, kb, a, b);
    });
}

type Rank = [number, number];

function sortKeyProjectOnly(n: Note, projectId: string): Rank {
    if (isWorkspaceNote(n)) {
        return [2, 0];
    }
    if (isProjectDirectOnProject(n, projectId)) {
        return [0, 0];
    }
    if (isTaskOnProject(n, projectId)) {
        return [1, 0];
    }
    return [2, 0];
}

function sortKeyWithTask(n: Note, projectId: string, taskId: string): Rank {
    if (isWorkspaceNote(n)) {
        return [2, 0];
    }
    if (n.task_id === taskId) {
        return [0, 0];
    }
    if (isProjectDirectOnProject(n, projectId)) {
        return [1, 0];
    }
    if (n.project_id === projectId) {
        return [1, 1];
    }
    return [2, 0];
}

function compareRank(ka: Rank, kb: Rank, a: Note, b: Note): number {
    if (ka[0] !== kb[0]) {
        return ka[0] - kb[0];
    }
    if (ka[1] !== kb[1]) {
        return ka[1] - kb[1];
    }
    return (b.created_at ?? '').localeCompare(a.created_at ?? '');
}

function compareByCreatedAtDesc(a: Note, b: Note): number {
    return (b.created_at ?? '').localeCompare(a.created_at ?? '');
}
