export type NotesListFilters = {
    projectId?: string;
    taskId?: string;
    visibility?: 'private' | 'shared' | '';
    search?: string;
};
