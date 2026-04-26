import type { Client, Project, Task, TimeEntry } from '@/packages/api/src';

const sameTimeEntryContext = (a: TimeEntry, b: TimeEntry) =>
    a.description === b.description &&
    a.task_id === b.task_id &&
    a.project_id === b.project_id &&
    a.tags.length === b.tags.length &&
    a.tags.every((tag) => b.tags.includes(tag)) &&
    a.billable === b.billable;

/**
 * Deduplicate finished time entries (same description, project, task, tags, billable).
 * Preserves order (e.g. newest-first from the API).
 */
export function dedupeTimeEntriesByContext(entries: TimeEntry[], onlyFinished = true): TimeEntry[] {
    const list = onlyFinished ? entries.filter((e) => e.end !== null) : [...entries];

    return list.filter((item, index, self) => {
        return index === self.findIndex((t) => sameTimeEntryContext(t, item));
    });
}

/**
 * For timer quick-pick chips: at most one entry per project (whichever is most
 * recent in the given order). Orphan tasks (no project) use one per task; entries
 * with neither use one per time entry. Avoids the same project label repeated when
 * only description/tags differed.
 */
export function dedupeTimeEntriesByQuickPickUniqueness(entries: TimeEntry[]): TimeEntry[] {
    const seen = new Set<string>();
    const out: TimeEntry[] = [];
    for (const e of entries) {
        let key: string;
        if (e.project_id) {
            key = `p:${e.project_id}`;
        } else if (e.task_id) {
            key = `t:${e.task_id}`;
        } else {
            key = `e:${e.id}`;
        }
        if (seen.has(key)) {
            continue;
        }
        seen.add(key);
        out.push(e);
    }
    return out;
}

/** Stable key for project + task in the focus quick-pick row (entry id when neither is set). */
export function timeEntryContextKeyForFocusPicker(
    e: Pick<TimeEntry, 'id' | 'project_id' | 'task_id'>
): string {
    const p = e.project_id ?? '';
    const t = e.task_id ?? '';
    if (p === '' && t === '') {
        return `e:${e.id}`;
    }
    return `${p}|${t}`;
}

/** One finished entry per distinct project+task (or per entry when no project/task). */
export function dedupeTimeEntriesByProjectTask(
    entries: TimeEntry[],
    onlyFinished = true
): TimeEntry[] {
    const list = onlyFinished ? entries.filter((e) => e.end !== null) : [...entries];
    const seen = new Set<string>();
    const out: TimeEntry[] = [];
    for (const e of list) {
        const key = timeEntryContextKeyForFocusPicker(e);
        if (seen.has(key)) {
            continue;
        }
        seen.add(key);
        out.push(e);
    }
    return out;
}

/**
 * Deduplicate and take the first N (e.g. dashboard card, quick-pick chips).
 * Use `quickPick: true` for the inline timer chip row (dedupe by project, not full context).
 */
export function dedupeRecentTimeEntries(
    entries: TimeEntry[],
    options: { onlyFinished?: boolean; maxItems: number; quickPick?: boolean } = { maxItems: 5 }
): TimeEntry[] {
    const { onlyFinished = true, maxItems, quickPick = false } = options;
    const list = onlyFinished ? entries.filter((e) => e.end !== null) : [...entries];
    const deduped = quickPick
        ? dedupeTimeEntriesByQuickPickUniqueness(list)
        : dedupeTimeEntriesByContext(list, false);
    return deduped.slice(0, maxItems);
}

export type TimeEntrySearchContext = {
    projects: Project[];
    tasks: Task[];
    clients: Client[];
};

/**
 * Whether the time entry matches a free-text search (description, project, task, or client name).
 */
export function timeEntryMatchesSearchText(
    entry: TimeEntry,
    queryRaw: string,
    context: TimeEntrySearchContext
): boolean {
    const q = queryRaw.toLowerCase().trim();
    if (q.length === 0) {
        return true;
    }

    if (entry.description?.toLowerCase().includes(q)) {
        return true;
    }

    const project = context.projects.find((p) => p.id === entry.project_id);
    if (project?.name.toLowerCase().includes(q)) {
        return true;
    }

    const task = context.tasks.find((t) => t.id === entry.task_id);
    if (task?.name.toLowerCase().includes(q)) {
        return true;
    }

    if (project?.client_id) {
        const client = context.clients.find((c) => c.id === project.client_id);
        if (client?.name.toLowerCase().includes(q)) {
            return true;
        }
    }

    return false;
}
