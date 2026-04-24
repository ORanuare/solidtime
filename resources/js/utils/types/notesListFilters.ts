export type NotesListFilters = {
    projectId?: string;
    taskId?: string;
    visibility?: 'private' | 'shared' | '';
    search?: string;
    /** `getNotes` query: exclude archived (false), only archived (true), or all */
    archived?: 'true' | 'false' | 'all';
};
