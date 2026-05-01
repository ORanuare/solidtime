import { useMutation, useQueryClient } from '@tanstack/vue-query';
import { api, type CreateOrgCalendarEventBody } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { useNotificationsStore } from '@/utils/notification';

export function useOrgCalendarEventsMutations() {
    const queryClient = useQueryClient();
    const { handleApiRequestNotifications } = useNotificationsStore();

    const { mutateAsync: createOrgCalendarEvent } = useMutation({
        mutationFn: async (body: CreateOrgCalendarEventBody) => {
            const organizationId = getCurrentOrganizationId();
            if (!organizationId) return;
            return handleApiRequestNotifications(
                () =>
                    api.createCalendarEvent(body, {
                        params: { organization: organizationId },
                    }),
                'Event created',
                'Failed to create event'
            );
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries', 'calendar'] });
        },
    });

    const { mutateAsync: updateOrgCalendarEvent } = useMutation({
        mutationFn: async ({ id, body }: { id: string; body: Record<string, unknown> }) => {
            const organizationId = getCurrentOrganizationId();
            if (!organizationId) return;
            return handleApiRequestNotifications(
                () =>
                    api.updateCalendarEvent(body, {
                        params: { organization: organizationId, calendarEvent: id },
                    }),
                'Event updated',
                'Failed to update event'
            );
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries', 'calendar'] });
        },
    });

    const { mutateAsync: deleteOrgCalendarEvent } = useMutation({
        mutationFn: async (id: string) => {
            const organizationId = getCurrentOrganizationId();
            if (!organizationId) return;
            return handleApiRequestNotifications(
                () =>
                    api.deleteCalendarEvent(undefined, {
                        params: { organization: organizationId, calendarEvent: id },
                    }),
                'Event deleted',
                'Failed to delete event'
            );
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries', 'calendar'] });
        },
    });

    return {
        createOrgCalendarEvent,
        updateOrgCalendarEvent,
        deleteOrgCalendarEvent,
    };
}
