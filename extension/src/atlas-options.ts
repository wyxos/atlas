/* global chrome */
import type { SiteCustomization } from './site-customizations';
import {
    createEmptySiteCustomization,
    deriveSiteCustomizationsFromLegacyStorage,
    normalizeSiteCustomizations,
    parseStoredSiteCustomizations,
    resolveStoredSiteCustomizationForHostname,
} from './site-customizations';

export const STORAGE_KEYS = {
    atlasDomain: 'atlasDomain',
    apiToken: 'apiToken',
    matchRules: 'matchRules',
    referrerQueryParamsToStripByDomain: 'referrerQueryParamsToStripByDomain',
    siteCustomizations: 'siteCustomizations',
    closeTabAfterQueueByDomain: 'closeTabAfterQueueByDomain',
    reactAllItemsInPostEnabled: 'reactAllItemsInPostEnabled',
    reactAllItemsInPostByDomain: 'reactAllItemsInPostByDomain',
} as const;

export const DEFAULT_ATLAS_DOMAIN = 'https://atlas.test';
export type DomainBooleanPreferences = Record<string, boolean>;
export const CLOSE_TAB_AFTER_QUEUE_MODES = ['off', 'queued', 'completed'] as const;
export type CloseTabAfterQueueMode = typeof CLOSE_TAB_AFTER_QUEUE_MODES[number];
export type CloseTabAfterQueueByDomain = Record<string, Exclude<CloseTabAfterQueueMode, 'off'>>;
export type ReactAllItemsInPostByDomain = DomainBooleanPreferences;
export type StoredOptions = {
    atlasDomain: string;
    apiToken: string;
    siteCustomizations: SiteCustomization[];
};

export function normalizeDomain(input: string): string {
    return input.trim().replace(/\/+$/, '');
}

export function validateDomain(input: string): string | null {
    if (input === '') {
        return 'Atlas domain is required.';
    }

    if (!/^https?:\/\//i.test(input)) {
        return 'Atlas domain must start with http:// or https://.';
    }

    try {
        new URL(input);
    } catch {
        return 'Atlas domain is not a valid URL.';
    }

    return null;
}

export function getStoredOptions(): Promise<StoredOptions> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.get(
            [
                STORAGE_KEYS.atlasDomain,
                STORAGE_KEYS.apiToken,
                STORAGE_KEYS.matchRules,
                STORAGE_KEYS.referrerQueryParamsToStripByDomain,
                STORAGE_KEYS.siteCustomizations,
            ],
            (stored: Record<string, unknown>) => {
                if (chrome.runtime.lastError) {
                    reject(new Error(chrome.runtime.lastError.message));
                    return;
                }

                const storedDomain = typeof stored.atlasDomain === 'string' ? normalizeDomain(stored.atlasDomain) : '';
                const atlasDomain = storedDomain !== '' ? storedDomain : DEFAULT_ATLAS_DOMAIN;
                const apiToken = typeof stored.apiToken === 'string' ? stored.apiToken.trim() : '';
                const siteCustomizations = stored[STORAGE_KEYS.siteCustomizations] !== undefined
                    ? parseStoredSiteCustomizations(stored[STORAGE_KEYS.siteCustomizations])
                    : deriveSiteCustomizationsFromLegacyStorage(
                        stored[STORAGE_KEYS.matchRules],
                        stored[STORAGE_KEYS.referrerQueryParamsToStripByDomain],
                    );

                resolve({
                    atlasDomain,
                    apiToken,
                    siteCustomizations,
                });
            },
        );
    });
}

export function saveStoredOptions(
    atlasDomain: string,
    apiToken: string,
    siteCustomizations: SiteCustomization[] = [],
): Promise<void> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.set(
            {
                [STORAGE_KEYS.atlasDomain]: atlasDomain,
                [STORAGE_KEYS.apiToken]: apiToken.trim(),
                [STORAGE_KEYS.siteCustomizations]: normalizeSiteCustomizations(siteCustomizations),
            },
            () => {
                if (chrome.runtime.lastError) {
                    reject(new Error(chrome.runtime.lastError.message));
                    return;
                }

                resolve();
            },
        );
    });
}

function saveStoredSiteCustomizations(siteCustomizations: SiteCustomization[]): Promise<void> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.set(
            {
                [STORAGE_KEYS.siteCustomizations]: normalizeSiteCustomizations(siteCustomizations),
            },
            () => {
                if (chrome.runtime.lastError) {
                    reject(new Error(chrome.runtime.lastError.message));
                    return;
                }

                resolve();
            },
        );
    });
}

function normalizePreferenceDomainKey(input: string): string {
    const trimmed = input.trim().toLowerCase();
    if (trimmed === '') {
        return '';
    }

    if (trimmed.includes('://')) {
        try {
            return new URL(trimmed).hostname.toLowerCase();
        } catch {
            return '';
        }
    }

    return trimmed.replace(/^\.+/, '').replace(/\.+$/, '');
}

export async function setSiteCustomizationEnabledForDomain(domain: string, enabled: boolean): Promise<void> {
    const normalizedDomain = normalizePreferenceDomainKey(domain);
    if (normalizedDomain === '') {
        return;
    }

    const { siteCustomizations } = await getStoredOptions();
    const existingCustomization = resolveStoredSiteCustomizationForHostname(siteCustomizations, normalizedDomain);
    const nextSiteCustomizations = existingCustomization === null
        ? [...siteCustomizations, {
            ...createEmptySiteCustomization(normalizedDomain),
            enabled,
        }]
        : siteCustomizations.map((customization) => customization.domain === existingCustomization.domain
            ? {
                ...customization,
                enabled,
            }
            : customization);

    await saveStoredSiteCustomizations(nextSiteCustomizations);
}

function parseStoredDomainBooleanPreferences(value: unknown): DomainBooleanPreferences {
    if (!value || typeof value !== 'object') {
        return {};
    }

    const parsed: DomainBooleanPreferences = {};
    for (const [rawKey, rawValue] of Object.entries(value as Record<string, unknown>)) {
        if (rawValue !== true && rawValue !== false) {
            continue;
        }

        const key = normalizePreferenceDomainKey(rawKey);
        if (key === '') {
            continue;
        }

        parsed[key] = rawValue;
    }

    return parsed;
}

function parseStoredCloseTabAfterQueueByDomain(value: unknown): CloseTabAfterQueueByDomain {
    if (!value || typeof value !== 'object') {
        return {};
    }

    const parsed: CloseTabAfterQueueByDomain = {};
    for (const [rawKey, rawValue] of Object.entries(value as Record<string, unknown>)) {
        const key = normalizePreferenceDomainKey(rawKey);
        if (key === '') {
            continue;
        }

        if (rawValue === true || rawValue === 'queued') {
            parsed[key] = 'queued';
            continue;
        }

        if (rawValue === 'completed') {
            parsed[key] = 'completed';
        }
    }

    return parsed;
}

export function getCloseTabAfterQueueByDomain(): Promise<CloseTabAfterQueueByDomain> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.get([STORAGE_KEYS.closeTabAfterQueueByDomain], (stored: Record<string, unknown>) => {
            if (chrome.runtime.lastError) {
                reject(new Error(chrome.runtime.lastError.message));
                return;
            }

            resolve(parseStoredCloseTabAfterQueueByDomain(stored[STORAGE_KEYS.closeTabAfterQueueByDomain]));
        });
    });
}

export async function getCloseTabAfterQueuePreferenceForHostname(hostname: string): Promise<CloseTabAfterQueueMode> {
    const key = normalizePreferenceDomainKey(hostname);
    if (key === '') {
        return 'off';
    }

    const preferences = await getCloseTabAfterQueueByDomain();
    return preferences[key] ?? 'off';
}

export async function setCloseTabAfterQueuePreferenceForHostname(
    hostname: string,
    mode: CloseTabAfterQueueMode,
): Promise<void> {
    const key = normalizePreferenceDomainKey(hostname);
    if (key === '') {
        return;
    }

    const preferences = await getCloseTabAfterQueueByDomain();
    const nextPreferences: CloseTabAfterQueueByDomain = { ...preferences };
    if (mode === 'queued' || mode === 'completed') {
        nextPreferences[key] = mode;
    } else {
        delete nextPreferences[key];
    }

    await new Promise<void>((resolve, reject) => {
        chrome.storage.local.set(
            {
                [STORAGE_KEYS.closeTabAfterQueueByDomain]: nextPreferences,
            },
            () => {
                if (chrome.runtime.lastError) {
                    reject(new Error(chrome.runtime.lastError.message));
                    return;
                }

                resolve();
            },
        );
    });
}

export function getReactAllItemsInPostByDomain(): Promise<ReactAllItemsInPostByDomain> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.get([STORAGE_KEYS.reactAllItemsInPostByDomain], (stored: Record<string, unknown>) => {
            if (chrome.runtime.lastError) {
                reject(new Error(chrome.runtime.lastError.message));
                return;
            }

            resolve(parseStoredDomainBooleanPreferences(stored[STORAGE_KEYS.reactAllItemsInPostByDomain]));
        });
    });
}

export async function getReactAllItemsInPostPreferenceForHostname(hostname: string): Promise<boolean> {
    const key = normalizePreferenceDomainKey(hostname);
    if (key === '') {
        return false;
    }

    const preferences = await getReactAllItemsInPostByDomain();
    return preferences[key] === true;
}

export async function setReactAllItemsInPostPreferenceForHostname(hostname: string, enabled: boolean): Promise<void> {
    const key = normalizePreferenceDomainKey(hostname);
    if (key === '') {
        return;
    }

    const preferences = await getReactAllItemsInPostByDomain();

    return new Promise((resolve, reject) => {
        chrome.storage.local.set(
            {
                [STORAGE_KEYS.reactAllItemsInPostByDomain]: {
                    ...preferences,
                    [key]: enabled,
                },
            },
            () => {
                if (chrome.runtime.lastError) {
                    reject(new Error(chrome.runtime.lastError.message));
                    return;
                }

                resolve();
            },
        );
    });
}
