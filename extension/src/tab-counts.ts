export type BrowserTabLike = {
    url?: string | null;
};

export type TabCountSummary = {
    similarDomainCount: number | null;
    totalCount: number;
};

const COMPARABLE_PROTOCOLS = new Set(['http:', 'https:']);
const COMMON_SECOND_LEVEL_TLDS = new Set([
    'ac',
    'co',
    'com',
    'edu',
    'gov',
    'net',
    'org',
]);

function isComparableProtocol(protocol: string): boolean {
    return COMPARABLE_PROTOCOLS.has(protocol);
}

function isIpv4Host(hostname: string): boolean {
    return /^\d{1,3}(?:\.\d{1,3}){3}$/.test(hostname);
}

function isIpv6Host(hostname: string): boolean {
    return hostname.includes(':');
}

function normalizeHostname(hostname: string): string {
    return hostname.trim().toLowerCase().replace(/\.+$/, '');
}

function resolveComparableHostnameKey(hostname: string): string | null {
    const normalizedHostname = normalizeHostname(hostname);
    if (normalizedHostname === '') {
        return null;
    }

    if (normalizedHostname === 'localhost' || isIpv4Host(normalizedHostname) || isIpv6Host(normalizedHostname)) {
        return normalizedHostname;
    }

    const labels = normalizedHostname.split('.').filter((label) => label !== '');
    if (labels.length <= 2) {
        return normalizedHostname;
    }

    const topLevelLabel = labels[labels.length - 1] ?? '';
    const secondLevelLabel = labels[labels.length - 2] ?? '';
    if (topLevelLabel.length === 2 && COMMON_SECOND_LEVEL_TLDS.has(secondLevelLabel) && labels.length >= 3) {
        return labels.slice(-3).join('.');
    }

    return labels.slice(-2).join('.');
}

export function resolveTabDomainGroupKey(value: string | null | undefined): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    try {
        const parsed = new URL(trimmed);
        if (!isComparableProtocol(parsed.protocol)) {
            return null;
        }

        return resolveComparableHostnameKey(parsed.hostname);
    } catch {
        return null;
    }
}

export function summarizeTabCounts(tabs: BrowserTabLike[], targetUrl: string | null | undefined): TabCountSummary {
    const totalCount = tabs.length;
    const targetDomainKey = resolveTabDomainGroupKey(targetUrl);
    if (targetDomainKey === null) {
        return {
            similarDomainCount: null,
            totalCount,
        };
    }

    let similarDomainCount = 0;
    for (const tab of tabs) {
        if (resolveTabDomainGroupKey(tab.url) === targetDomainKey) {
            similarDomainCount += 1;
        }
    }

    return {
        similarDomainCount,
        totalCount,
    };
}

export function formatTabCountSummary(summary: TabCountSummary | null): string {
    if (summary === null) {
        return '—';
    }

    return `${summary.similarDomainCount ?? '—'}/${summary.totalCount}`;
}
