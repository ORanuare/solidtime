import type { Note, Project } from '@/packages/api/src';

/**
 * For org-wide note lists: hide notes linked to archived (completed) projects unless the user
 * picks “Archived” or “All” (same idea as the Projects list).
 * Workspace / org notes without a project are treated like “active” and only show when scope is `active` or `all`.
 */
export function filterNotesByWorkspaceProjectArchive(
    notes: ReadonlyArray<Note>,
    scope: 'active' | 'archived' | 'all',
    projects: ReadonlyArray<Project>
): Note[] {
    if (scope === 'all') {
        return [...notes];
    }
    return notes.filter((n) => {
        if (!n.project_id) {
            return scope === 'active';
        }
        const p = projects.find((x) => x.id === n.project_id);
        if (!p) {
            return true;
        }
        if (scope === 'active') {
            return !p.is_archived;
        }
        return p.is_archived;
    });
}
