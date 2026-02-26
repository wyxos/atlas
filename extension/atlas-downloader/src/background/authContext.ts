export type BrowserCookie = {
  domain?: unknown;
  path?: unknown;
  name?: unknown;
  value?: unknown;
  secure?: unknown;
  hostOnly?: unknown;
  expirationDate?: unknown;
  session?: unknown;
};

export type ExtensionAuthCookie = {
  domain: string;
  path: string;
  name: string;
  value: string;
  secure: boolean;
  host_only: boolean;
  expires: number | null;
};

export type ExtensionAuthContext = {
  source_url: string;
  user_agent: string;
  cookies: ExtensionAuthCookie[];
};

type PayloadWithAuthContext = {
  url?: unknown;
  referrer_url?: unknown;
  tag_name?: unknown;
  download_via?: unknown;
  source?: unknown;
};

type AuthContextDeps = {
  getCookies: (url: string) => Promise<BrowserCookie[]>;
  userAgent: string;
};

const MAX_AUTH_COOKIES = 120;

export async function attachAuthContextToPayload(
  payload: unknown,
  deps: AuthContextDeps,
): Promise<unknown> {
  if (!isRecord(payload)) {
    return payload;
  }

  if (!shouldAttachAuthContext(payload)) {
    return payload;
  }

  const sourceUrl = normalizeHttpUrl(
    typeof payload.referrer_url === 'string' ? payload.referrer_url : '',
  ) || normalizeHttpUrl(typeof payload.url === 'string' ? payload.url : '');
  if (!sourceUrl) {
    return payload;
  }

  const candidateUrls = buildAuthUrlCandidates(payload, sourceUrl);
  const cookies = await collectCookies(candidateUrls, deps.getCookies);

  return {
    ...payload,
    auth_context: {
      source_url: sourceUrl,
      user_agent: deps.userAgent,
      cookies,
    } satisfies ExtensionAuthContext,
  };
}

function shouldAttachAuthContext(payload: PayloadWithAuthContext): boolean {
  const downloadVia = asLowerString(payload.download_via);
  if (downloadVia === 'yt-dlp') {
    return true;
  }

  const tagName = asLowerString(payload.tag_name);
  if (tagName === 'video' || tagName === 'iframe') {
    return true;
  }

  const source = asLowerString(payload.source);
  if (source.includes('x.com') || source.includes('twitter.com') || source.includes('facebook.com')) {
    return true;
  }

  const mediaUrl = normalizeHttpUrl(typeof payload.url === 'string' ? payload.url : '');
  if (!mediaUrl) {
    return false;
  }

  const host = getUrlHost(mediaUrl);
  return host.endsWith('x.com')
    || host.endsWith('twitter.com')
    || host.endsWith('facebook.com')
    || host.endsWith('fb.watch');
}

function buildAuthUrlCandidates(payload: PayloadWithAuthContext, fallbackUrl: string): string[] {
  const candidates = new Set<string>();

  const pushUrl = (value: unknown): void => {
    if (typeof value !== 'string') {
      return;
    }

    const normalized = normalizeHttpUrl(value);
    if (!normalized) {
      return;
    }

    candidates.add(normalized);
    for (const alias of buildHostAliasUrls(normalized)) {
      candidates.add(alias);
    }
  };

  pushUrl(payload.url);
  pushUrl(payload.referrer_url);
  pushUrl(fallbackUrl);

  return Array.from(candidates);
}

function buildHostAliasUrls(url: string): string[] {
  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    return [];
  }

  const host = parsed.hostname.toLowerCase();
  const aliases = hostAliasesFor(host);
  if (aliases.length === 0) {
    return [];
  }

  return aliases.map((aliasHost) => {
    const aliasUrl = new URL(url);
    aliasUrl.hostname = aliasHost;

    return aliasUrl.toString();
  });
}

function hostAliasesFor(host: string): string[] {
  if (host === 'x.com') {
    return ['www.x.com', 'twitter.com', 'www.twitter.com'];
  }

  if (host === 'www.x.com') {
    return ['x.com', 'twitter.com', 'www.twitter.com'];
  }

  if (host === 'twitter.com') {
    return ['www.twitter.com', 'x.com', 'www.x.com'];
  }

  if (host === 'www.twitter.com') {
    return ['twitter.com', 'x.com', 'www.x.com'];
  }

  if (host === 'facebook.com') {
    return ['www.facebook.com', 'm.facebook.com'];
  }

  if (host === 'www.facebook.com') {
    return ['facebook.com', 'm.facebook.com'];
  }

  if (host === 'm.facebook.com') {
    return ['facebook.com', 'www.facebook.com'];
  }

  return [];
}

async function collectCookies(
  urls: string[],
  getCookies: (url: string) => Promise<BrowserCookie[]>,
): Promise<ExtensionAuthCookie[]> {
  const deduped = new Map<string, ExtensionAuthCookie>();

  for (const url of urls) {
    let cookies: BrowserCookie[] = [];
    try {
      cookies = await getCookies(url);
    } catch {
      continue;
    }

    for (const cookie of cookies) {
      const normalized = normalizeCookie(cookie);
      if (!normalized) {
        continue;
      }

      const key = `${normalized.domain}\n${normalized.path}\n${normalized.name}`;
      if (deduped.has(key)) {
        continue;
      }

      deduped.set(key, normalized);

      if (deduped.size >= MAX_AUTH_COOKIES) {
        return Array.from(deduped.values());
      }
    }
  }

  return Array.from(deduped.values());
}

function normalizeCookie(cookie: BrowserCookie): ExtensionAuthCookie | null {
  const domain = typeof cookie.domain === 'string' ? cookie.domain.trim() : '';
  const name = typeof cookie.name === 'string' ? cookie.name.trim() : '';

  if (!domain || !name) {
    return null;
  }

  const path = typeof cookie.path === 'string' && cookie.path.trim() ? cookie.path.trim() : '/';
  const value = typeof cookie.value === 'string' ? cookie.value : '';
  const secure = cookie.secure === true;
  const hostOnly = cookie.hostOnly === true;
  const expiry = Number(cookie.expirationDate);
  const expires = cookie.session === true || !Number.isFinite(expiry) || expiry <= 0
    ? null
    : Math.floor(expiry);

  return {
    domain,
    path,
    name,
    value,
    secure,
    host_only: hostOnly,
    expires,
  };
}

function normalizeHttpUrl(value: string): string | null {
  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  try {
    const parsed = new URL(trimmed);
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
      return null;
    }

    return parsed.toString();
  } catch {
    return null;
  }
}

function getUrlHost(url: string): string {
  try {
    return new URL(url).hostname.toLowerCase();
  } catch {
    return '';
  }
}

function asLowerString(value: unknown): string {
  return typeof value === 'string' ? value.trim().toLowerCase() : '';
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}
