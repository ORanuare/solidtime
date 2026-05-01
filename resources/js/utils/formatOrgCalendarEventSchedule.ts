import type { OrgCalendarEvent } from '@/packages/api/src';
import {
    formatStartEnd,
    getLocalizedDayJs,
    type TimeFormat,
} from '@/packages/ui/src/utils/time';

/** Human-readable schedule line for a calendar event (matches focus card / calendar chips). */
export function formatOrgCalendarEventSchedule(
    ev: Pick<OrgCalendarEvent, 'starts_at' | 'ends_at' | 'all_day'>,
    orgTimeFormat: TimeFormat
): string {
    if (!ev.starts_at || !ev.ends_at) {
        return '';
    }
    if (ev.all_day) {
        const s = getLocalizedDayJs(ev.starts_at);
        const e = getLocalizedDayJs(ev.ends_at).subtract(1, 'millisecond');
        if (s.format('YYYY-MM-DD') === e.format('YYYY-MM-DD')) {
            return `All day · ${s.format('MMM D')}`;
        }
        return `All day · ${s.format('MMM D')} – ${e.format('MMM D')}`;
    }
    return formatStartEnd(ev.starts_at, ev.ends_at, orgTimeFormat);
}
