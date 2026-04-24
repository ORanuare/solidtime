import { defineStore } from 'pinia';
import type { CreateNoteBody, Note, UpdateNoteBody } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { api } from '@/packages/api/src';
import { useNotificationsStore } from '@/utils/notification';
import { useMutation, useQueryClient } from '@tanstack/vue-query';

export const useNotesStore = defineStore('notes', () => {
    const { handleApiRequestNotifications } = useNotificationsStore();
    const queryClient = useQueryClient();

    async function deleteNote(noteId: string) {
        const organizationId = getCurrentOrganizationId();
        if (organizationId) {
            await handleApiRequestNotifications(
                () =>
                    api.deleteNote(undefined, {
                        params: {
                            organization: organizationId,
                            note: noteId,
                        },
                    }),
                'Note deleted',
                'Failed to delete note'
            );
            queryClient.invalidateQueries({ queryKey: ['notes'] });
        }
    }

    async function createNote(body: CreateNoteBody): Promise<Note | undefined> {
        const organizationId = getCurrentOrganizationId();
        if (organizationId) {
            const response = await handleApiRequestNotifications(
                () =>
                    api.createNote(body, {
                        params: { organization: organizationId },
                    }),
                'Note created',
                'Failed to create note'
            );
            if (response?.data) {
                queryClient.invalidateQueries({ queryKey: ['notes'] });
                return response.data;
            }
        } else {
            throw new Error('No organization');
        }
    }

    const { mutateAsync: updateNote } = useMutation({
        mutationFn: async ({ noteId, body }: { noteId: string; body: UpdateNoteBody }) => {
            const organizationId = getCurrentOrganizationId();
            if (organizationId) {
                return await handleApiRequestNotifications(
                    () =>
                        api.updateNote(body, {
                            params: { organization: organizationId, note: noteId },
                        }),
                    'Note updated',
                    'Failed to update note'
                );
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['notes'] });
        },
    });

    return { createNote, updateNote, deleteNote };
});
