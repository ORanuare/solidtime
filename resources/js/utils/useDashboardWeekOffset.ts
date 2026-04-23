import { useStorage } from '@vueuse/core';
import { computed, watch } from 'vue';

export const DASHBOARD_WEEK_OFFSET_KEY = 'dashboard-week-offset';

const MIN_OFFSET = -1000;
const MAX_OFFSET = 0;

/**
 * 0 = this week, -1 = previous week, etc. Persists in localStorage.
 */
export function useDashboardWeekOffset() {
    const weekOffset = useStorage(DASHBOARD_WEEK_OFFSET_KEY, 0);

    watch(
        weekOffset,
        (v) => {
            if (v > MAX_OFFSET) {
                weekOffset.value = MAX_OFFSET;
            } else if (v < MIN_OFFSET) {
                weekOffset.value = MIN_OFFSET;
            }
        },
        { immediate: true }
    );

    const weekOffsetQueries = computed(() => ({
        week_offset: weekOffset.value,
    }));

    function shiftWeekBy(delta: number) {
        weekOffset.value = Math.max(MIN_OFFSET, Math.min(MAX_OFFSET, weekOffset.value + delta));
    }

    function goToThisWeek() {
        weekOffset.value = 0;
    }

    return { weekOffset, weekOffsetQueries, shiftWeekBy, goToThisWeek, MIN_OFFSET, MAX_OFFSET };
}
