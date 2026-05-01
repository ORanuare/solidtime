import { useQuery } from '@tanstack/vue-query';
import { api, type OrgCalendarEvent } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { computed, type Ref } from 'vue';
import {
    createCalendarQueryKey,
    getExpandedCalendarDateRange,
} from '@/utils/useTimeEntriesCalendarQuery';

/** Optional API filters (matches calendar-events index query params). */
export type OrgCalendarEventsListQueries = {
    project_id?: string;
    task_id?: string;
    visibility?: 'private' | 'shared';
};

export async function fetchAllOrgCalendarEvents(
    organizationId: string,
    start: string,
    end: string,
    listQueries?: OrgCalendarEventsListQueries
): Promise<OrgCalendarEvent[]> {
    const all: OrgCalendarEvent[] = [];
    let page = 1;
    let lastPage = 1;

    do {
        const response = await api.getCalendarEvents({
            params: { organization: organizationId },
            queries: {
                start,
                end,
                page,
                ...(listQueries?.task_id
                    ? { task_id: listQueries.task_id }
                    : listQueries?.project_id
                      ? { project_id: listQueries.project_id }
                      : {}),
                ...(listQueries?.visibility ? { visibility: listQueries.visibility } : {}),
            },
        });
        all.push(...response.data);
        lastPage = response.meta.last_page;
        page++;
    } while (page <= lastPage);

    return all;
}

export function useOrgCalendarEventsQuery(
    calendarStart: Ref<Date | undefined>,
    calendarEnd: Ref<Date | undefined>,
    enabled: Ref<boolean>
) {
    const expandedDateRange = computed(() => {
        if (!calendarStart.value || !calendarEnd.value) {
            return { start: null as string | null, end: null as string | null };
        }
        return getExpandedCalendarDateRange(calendarStart.value, calendarEnd.value);
    });

    const enableQuery = computed(() => {
        return (
            enabled.value &&
            !!getCurrentOrganizationId() &&
            !!expandedDateRange.value.start &&
            !!expandedDateRange.value.end
        );
    });

    return useQuery({
        queryKey: computed(() => [
            ...createCalendarQueryKey(
                expandedDateRange.value.start,
                expandedDateRange.value.end,
                getCurrentOrganizationId()
            ),
            'calendarEvents',
        ]),
        enabled: enableQuery,
        placeholderData: (previousData) => previousData,
        queryFn: async () => {
            return fetchAllOrgCalendarEvents(
                getCurrentOrganizationId() || '',
                expandedDateRange.value.start!,
                expandedDateRange.value.end!
            );
        },
        staleTime: 1000 * 30,
    });
}

function orgCalendarEventsFilterSegment(listQueries: OrgCalendarEventsListQueries | undefined): string {
    const q = listQueries ?? {};
    const vis = q.visibility ?? 'all';
    if (q.task_id) {
        return `${vis}:task:${q.task_id}`;
    }
    if (q.project_id) {
        return `${vis}:project:${q.project_id}`;
    }
    return `${vis}:all`;
}

/**
 * Calendar events for an explicit ISO start/end window (e.g. Events page).
 * Shares invalidation with calendar/focus via createCalendarQueryKey + calendarEvents prefix.
 */
export function useOrgCalendarEventsInRangeQuery(
    rangeStartIso: Ref<string | null>,
    rangeEndIso: Ref<string | null>,
    listQueries: Ref<OrgCalendarEventsListQueries>,
    enabled: Ref<boolean>
) {
    const filterKey = computed(() => orgCalendarEventsFilterSegment(listQueries.value));

    const enableQuery = computed(
        () =>
            enabled.value &&
            !!getCurrentOrganizationId() &&
            !!rangeStartIso.value &&
            !!rangeEndIso.value
    );

    return useQuery({
        queryKey: computed(() => [
            ...createCalendarQueryKey(
                rangeStartIso.value,
                rangeEndIso.value,
                getCurrentOrganizationId()
            ),
            'calendarEvents',
            'eventsPage',
            filterKey.value,
        ]),
        enabled: enableQuery,
        placeholderData: (previousData) => previousData,
        queryFn: async () => {
            return fetchAllOrgCalendarEvents(
                getCurrentOrganizationId() || '',
                rangeStartIso.value!,
                rangeEndIso.value!,
                listQueries.value
            );
        },
        staleTime: 1000 * 30,
    });
}
