import type { Component } from 'vue';
import {
    BuildingOffice2Icon,
    FolderIcon,
    ListBulletIcon,
    Squares2X2Icon,
} from '@heroicons/vue/16/solid';
import type { NoteNotableLevel } from '@/utils/noteNotableLevel';

export type NotePillStyle = {
    icon: Component;
    shortLabel: string;
    /** Chip background + border (same as `NoteNotablePill` level chip). */
    chipClass: string;
    /** Icon color class. */
    iconClass: string;
};

/**
 * Notable level chips: workspace, project, task (single source of truth with `NoteNotablePill.vue`).
 */
export const NOTE_NOTABLE_LEVEL_PILL_STYLE: Record<NoteNotableLevel, NotePillStyle> = {
    workspace: {
        icon: BuildingOffice2Icon,
        shortLabel: 'Workspace',
        chipClass:
            'border-violet-500/35 bg-violet-500/10 text-violet-800 dark:border-violet-400/30 dark:bg-violet-500/15 dark:text-violet-100',
        iconClass: 'text-violet-600 dark:text-violet-300',
    },
    project: {
        icon: FolderIcon,
        shortLabel: 'Project',
        chipClass:
            'border-sky-500/40 bg-sky-500/10 text-sky-900 dark:border-sky-400/35 dark:bg-sky-500/15 dark:text-sky-100',
        iconClass: 'text-sky-600 dark:text-sky-300',
    },
    task: {
        icon: ListBulletIcon,
        shortLabel: 'Task',
        chipClass:
            'border-emerald-500/40 bg-emerald-500/10 text-emerald-900 dark:border-emerald-400/35 dark:bg-emerald-500/15 dark:text-emerald-100',
        iconClass: 'text-emerald-600 dark:text-emerald-300',
    },
};

/** “All” filter (not a note level): neutral styling in the same family as the chips. */
export const NOTE_LIST_FILTER_ALL_PILL_STYLE: NotePillStyle = {
    icon: Squares2X2Icon,
    shortLabel: 'All',
    chipClass:
        'border-zinc-500/35 bg-zinc-500/10 text-zinc-800 dark:border-zinc-500/30 dark:bg-zinc-500/15 dark:text-zinc-100',
    iconClass: 'text-zinc-600 dark:text-zinc-300',
};

export type TimerFocusNotesListMode = 'all' | 'project' | 'task' | 'workspace';

export function getNoteListFilterPillStyle(mode: TimerFocusNotesListMode): NotePillStyle {
    if (mode === 'all') {
        return NOTE_LIST_FILTER_ALL_PILL_STYLE;
    }
    return NOTE_NOTABLE_LEVEL_PILL_STYLE[mode];
}
