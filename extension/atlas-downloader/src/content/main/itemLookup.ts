import { buildLookupKeys } from '../lookupKeys';
import { filterEligibleLookupUrls } from '../items';

type LookupItem = {
  url?: string | null;
  referrer_url?: string | null;
};

export function buildItemLookupKeys(item: LookupItem | null | undefined): string[] {
  const url = (item?.url || '').trim();
  const referrerUrl = (item?.referrer_url || '').trim();
  return filterEligibleLookupUrls(buildLookupKeys(url, referrerUrl));
}

export function buildPrimaryItemLookupUrl(item: LookupItem | null | undefined): string {
  return buildItemLookupKeys(item)[0] || '';
}
