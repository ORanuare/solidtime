import type { Organization } from '@/packages/api/src';
import { formatCents, getOrganizationCurrencySymbol } from '@/packages/ui/src/utils/money';

export type OrgMoneyFormattingPrefs = Pick<Organization, 'currency_format' | 'number_format'>;

export type BillableCurrencyMinorRow = {
    currency_code: string;
    minor_units: number;
};

/** Format minor units using workspace number/currency-format prefs and per-row ISO symbol. */
export function formatBillableMinorForIso(
    minorUnits: number,
    isoCode: string,
    organization: OrgMoneyFormattingPrefs | null | undefined
): string | null {
    if (!organization) {
        return null;
    }
    return formatCents(
        minorUnits,
        isoCode,
        organization.currency_format,
        getOrganizationCurrencySymbol(isoCode),
        organization.number_format
    ) ?? null;
}
