import { useQuery } from '@tanstack/vue-query';
import { computed, type ComputedRef, type Ref } from 'vue';
import { fetchAllOrgCalendarEvents, type OrgCalendarEventsListQueries } from '@/utils/useOrgCalendarEventsQuery';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { canViewCalendarEvents } from '@/utils/permissions';
import { getDayJsInstance } from '@/packages/ui/src/utils/time';
import { getUserTimezone } from '@/packages/ui/src/utils/settings';
import { createCalendarQueryKey } from '@/utils/useTimeEntriesCalendarQuery';

const FOCUS_RANGE_DAYS = 21;

function focusWindowIsoRange(): { start: string; end: string } {
    const d = getDayJsInstance();
    const tz = getUserTimezone();
    const start = d().tz(tz).startOf('day').utc().format();
    const end = d().tz(tz).add(FOCUS_RANGE_DAYS, 'day').endOf('day').utc().format();
    return { start, end };
}

/**
 * Calendar events for the timer focus sidebar (~3 weeks from local today).
 * Uses the same query-key prefix as org calendar queries so create/update/delete mutations invalidate it.
 */
export function useTimerFocusCalendarEventsQuery(
    isTimerFocusOpen: Ref<boolean>,
    listQueries: ComputedRef<OrgCalendarEventsListQueries | undefined>
) {
    const windowRange = computed(() => focusWindowIsoRange());

    const filterKey = computed(() => {
        const q = listQueries.value;
        if (q?.task_id) {
            return `task:${q.task_id}`;
        }
        if (q?.project_id) {
            return `project:${q.project_id}`;
        }
        return 'all';
    });

    const enableQuery = computed(
        () =>
            isTimerFocusOpen.value &&
            canViewCalendarEvents() &&
            Boolean(getCurrentOrganizationId())
    );

    return useQuery({
        queryKey: computed(() => [
            ...createCalendarQueryKey(
                windowRange.value.start,
                windowRange.value.end,
                getCurrentOrganizationId()
            ),
            'calendarEvents',
            'timerFocus',
            filterKey.value,
        ]),
        enabled: enableQuery,
        placeholderData: (previousData) => previousData,
        queryFn: async () => {
            return fetchAllOrgCalendarEvents(
                getCurrentOrganizationId() || '',
                windowRange.value.start,
                windowRange.value.end,
                listQueries.value
            );
        },
        staleTime: 1000 * 30,
    });
}
