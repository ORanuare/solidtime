import { useQuery } from '@tanstack/vue-query';
import { storeToRefs } from 'pinia';
import { computed, type Ref } from 'vue';
import dayjs from 'dayjs';
import type { TimeEntry } from '@/packages/api/src';
import { getDayJsInstance, getLocalizedDateFromTimestamp, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import { fetchAllCalendarEntries } from '@/utils/useTimeEntriesCalendarQuery';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';

/**
 * Sums time entry seconds for a local calendar day, matching FullCalendar
 * `dailyTotals`: full duration is attributed to the entry's local start date
 * (no split at midnight for overnight runs).
 */
function sumSecondsForStartDay(
    entries: TimeEntry[],
    dayKey: string,
    now: Ref<ReturnType<typeof dayjs> | null>
): number {
    let total = 0;
    for (const entry of entries) {
        if (getLocalizedDateFromTimestamp(entry.start) !== dayKey) {
            continue;
        }
        if (entry.end !== null) {
            total += getDayJsInstance()(entry.end).diff(getDayJsInstance()(entry.start), 'second');
        } else {
            const endPoint = now.value ?? dayjs().utc();
            total += endPoint.diff(getDayJsInstance()(entry.start), 'second');
        }
    }
    return Math.max(0, total);
}

export function useTimerFocusTodayWorked(isTimerFocusOpen: Ref<boolean>) {
    const { now } = storeToRefs(useCurrentTimeEntryStore());

    const organizationId = computed(() => getCurrentOrganizationId());
    const memberId = computed(() => getCurrentMembershipId());
    const localDateString = computed(() => getLocalizedDayJs().format('YYYY-MM-DD'));
    const rangeStart = computed(() => getLocalizedDayJs().startOf('day').utc().format());
    const rangeEnd = computed(() => getLocalizedDayJs().add(1, 'day').startOf('day').utc().format());

    const queryEnabled = computed(
        () =>
            isTimerFocusOpen.value &&
            !!organizationId.value &&
            !!memberId.value
    );

    const { data, isLoading } = useQuery({
        queryKey: computed(() => [
            'timeEntries',
            'timer-focus-today',
            organizationId.value,
            memberId.value,
            localDateString.value,
        ]),
        queryFn: () => {
            const org = organizationId.value;
            if (!org) {
                return { data: [] as TimeEntry[] };
            }
            return fetchAllCalendarEntries(
                org,
                memberId.value,
                rangeStart.value,
                rangeEnd.value
            );
        },
        enabled: queryEnabled,
        staleTime: 30_000,
    });

    const totalSeconds = computed(() =>
        sumSecondsForStartDay(data.value?.data ?? [], localDateString.value, now)
    );

    return {
        totalSeconds,
        isLoading,
    };
}
