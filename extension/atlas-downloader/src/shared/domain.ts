const COMMON_MULTI_PART_TLDS = new Set([
  // UK
  'co.uk',
  'org.uk',
  'ac.uk',
  'gov.uk',
  // AU
  'com.au',
  'net.au',
  'org.au',
  'edu.au',
  'gov.au',
  // NZ
  'co.nz',
  'org.nz',
  'ac.nz',
  // JP
  'co.jp',
  'or.jp',
  'ne.jp',
  // KR
  'co.kr',
  // BR
  'com.br',
  // MX
  'com.mx',
]);

export function registrableDomain(hostname: string): string {
  const host = (hostname || '').trim().toLowerCase();
  if (!host) return '';

  const parts = host.split('.').filter(Boolean);
  if (parts.length <= 2) {
    return host;
  }

  const last2 = parts.slice(-2).join('.');
  const last3 = parts.slice(-3).join('.');

  if (COMMON_MULTI_PART_TLDS.has(last2) && parts.length >= 3) {
    return last3;
  }

  return last2;
}

export function registrableDomainFromUrl(url: string): string {
  const raw = (url || '').trim();
  if (!raw) return '';

  try {
    const host = new URL(raw).hostname;
    return registrableDomain(host);
  } catch {
    return '';
  }
}

