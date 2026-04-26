import type { Note } from '@/packages/api/src';

export type NoteNotableLevel = 'workspace' | 'project' | 'task';

export function getNoteNotableLevel(
    note: Pick<Note, 'notable_type' | 'notable_id'>
): NoteNotableLevel {
    if (note.notable_type == null && note.notable_id == null) {
        return 'workspace';
    }
    if (note.notable_type === 'project') {
        return 'project';
    }
    if (note.notable_type === 'task') {
        return 'task';
    }
    return 'workspace';
}

/**
 * Name of the linked project or task, without the "Project:" / "Task:" prefix.
 * Empty for workspace-level notes.
 */
export function getNoteNotableEntityName(
    note: Pick<Note, 'notable_type' | 'notable_label'>
): string {
    if (getNoteNotableLevel(note) === 'workspace') {
        return '';
    }
    const label = note.notable_label;
    if (label.startsWith('Task: ')) {
        return label.slice(6);
    }
    if (label.startsWith('Project: ')) {
        return label.slice(9);
    }
    return label === 'Workspace' ? '' : label;
}

export function isWorkspaceNote(n: Note): boolean {
    return getNoteNotableLevel(n) === 'workspace';
}
