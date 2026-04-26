import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { api } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';
import type { Note } from '@/packages/api/src';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';
import { fetchAllPages } from '@/utils/fetchAllPages';
import type { NotesListFilters } from '@/utils/types/notesListFilters';

export async function fetchAllNotes(
    organizationId: string,
    filters?: NotesListFilters
): Promise<Note[]> {
    return fetchAllPages((page) =>
        api.getNotes({
            params: { organization: organizationId },
            queries: {
                page,
                project_id: filters?.projectId || undefined,
                task_id: filters?.taskId || undefined,
                visibility: filters?.visibility || undefined,
                search: filters?.search || undefined,
                ...(filters?.archived !== undefined ? { archived: filters.archived } : {}),
            },
        })
    );
}

export function useNotesQuery(
    filters?: MaybeRefOrGetter<NotesListFilters | undefined>,
    options?: { enabled?: MaybeRefOrGetter<boolean> }
) {
    const queryClient = useQueryClient();

    const query = useQuery({
        queryKey: computed(() => {
            const f = toValue(filters);
            return [
                'notes',
                getCurrentOrganizationId(),
                f?.projectId,
                f?.taskId,
                f?.visibility,
                f?.search,
                f?.archived,
            ] as const;
        }),
        queryFn: async () => {
            const organizationId = getCurrentOrganizationId();
            if (!organizationId) {
                throw new Error('No organization');
            }
            const f = toValue(filters);
            const data = await fetchAllNotes(organizationId, f);
            return { data };
        },
        enabled: () => {
            if (!getCurrentOrganizationId()) {
                return false;
            }
            if (options?.enabled !== undefined && !toValue(options.enabled)) {
                return false;
            }

            return true;
        },
        staleTime: 1000 * 30,
    });

    const notes = computed<Note[]>(() => query.data.value?.data ?? []);

    const invalidateNotes = () => {
        queryClient.invalidateQueries({ queryKey: ['notes'] });
    };

    return {
        ...query,
        notes,
        invalidateNotes,
    };
}
