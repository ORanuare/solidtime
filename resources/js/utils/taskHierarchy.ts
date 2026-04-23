import type { Task } from '@/packages/api/src';

export type TaskWithDepth = { task: Task; depth: 0 | 1 };

function taskCreatedAtMs(t: Task): number {
    return t.created_at ? new Date(t.created_at).getTime() : 0;
}

/**
 * Orders tasks for display: root tasks (or orphans) by created_at desc, each followed by its sub-tasks.
 * If a sub-task's parent is not in `tasks`, the sub-task is shown as a root.
 */
export function orderTasksWithSubTasks(tasks: Task[]): TaskWithDepth[] {
    const idSet = new Set(tasks.map((t) => t.id));
    const byParent = new Map<string | null, Task[]>();

    for (const t of tasks) {
        const rawParent = t.parent_task_id;
        const parentKey =
            rawParent !== null && rawParent !== undefined && idSet.has(rawParent) ? rawParent : null;
        const list = byParent.get(parentKey);
        if (list) {
            list.push(t);
        } else {
            byParent.set(parentKey, [t]);
        }
    }

    const cmpDesc = (a: Task, b: Task) => taskCreatedAtMs(b) - taskCreatedAtMs(a);
    for (const list of byParent.values()) {
        list.sort(cmpDesc);
    }

    const roots = byParent.get(null) ?? [];
    roots.sort(cmpDesc);

    const out: TaskWithDepth[] = [];
    for (const root of roots) {
        out.push({ task: root, depth: 0 });
        const children = byParent.get(root.id) ?? [];
        children.sort(cmpDesc);
        for (const c of children) {
            out.push({ task: c, depth: 1 });
        }
    }

    return out;
}
