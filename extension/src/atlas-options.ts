/* global chrome */
import { createAtlasApiHeaders, createAtlasFetchAuthOptions, hasAtlasApiAuth } from './atlas-auth';
import { requestAtlasViaRuntime } from './atlas-runtime-request';
import {
    forgetStoredOptionsLoad,
    getFreshStoredOptions,
    getStoredOptionsCacheGeneration,
    getStoredOptionsLoad,
    invalidateStoredOptionsCache,
    rememberStoredOptions,
    rememberStoredOptionsLoad,
    storedOptionsCacheKey,
} from './stored-options-cache';
import type { SiteCustomization } from './site-customizations';
import {
    deriveSiteCustomizationsFromLegacyStorage,
    normalizeSiteCustomizations,
    parseStoredSiteCustomizations,
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
    settingsMigrationByDomain: 'settingsMigrationByDomain',
    settingsUpdatedAt: 'settingsUpdatedAt',
} as const;

export const DEFAULT_ATLAS_DOMAIN = 'https://atlas.test';
export type DomainBooleanPreferences = Record<string, boolean>;
export const CLOSE_TAB_AFTER_QUEUE_MODES = ['off', 'queued', 'completed'] as const;
export type CloseTabAfterQueueMode = typeof CLOSE_TAB_AFTER_QUEUE_MODES[number];
export type CloseTabAfterQueueByDomain = Record<string, Exclude<CloseTabAfterQueueMode, 'off'>>;
export type ReactAllItemsInPostByDomain = DomainBooleanPreferences;
export type ExtensionSettings = {
    version: 1;
    siteCustomizations: SiteCustomization[];
    closeTabAfterQueueByDomain: CloseTabAfterQueueByDomain;
    reactAllItemsInPostByDomain: ReactAllItemsInPostByDomain;
};
export type StoredConnectionOptions = {
    atlasDomain: string;
    apiToken: string;
};
export type StoredOptions = StoredConnectionOptions & ExtensionSettings;

type LocalOptionsSnapshot = StoredConnectionOptions & {
    localSettings: ExtensionSettings;
    hasLocalSettings: boolean;
    settingsMigrationByDomain: Record<string, true>;
    settingsUpdatedAt: string;
};

type RemoteSettingsPayload = {
    settings?: unknown;
};

const SETTINGS_VERSION = 1 as const;
const LOCAL_EXTENSION_SETTING_KEYS = [
    STORAGE_KEYS.matchRules,
    STORAGE_KEYS.referrerQueryParamsToStripByDomain,
    STORAGE_KEYS.siteCustomizations,
    STORAGE_KEYS.closeTabAfterQueueByDomain,
    STORAGE_KEYS.reactAllItemsInPostEnabled,
    STORAGE_KEYS.reactAllItemsInPostByDomain,
] as const;

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

function emptyExtensionSettings(): ExtensionSettings {
    return {
        version: SETTINGS_VERSION,
        siteCustomizations: [],
        closeTabAfterQueueByDomain: {},
        reactAllItemsInPostByDomain: {},
    };
}

export function normalizePreferenceDomainKey(input: string): string {
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

function parseStoredMigrationMap(value: unknown): Record<string, true> {
    if (!value || typeof value !== 'object') {
        return {};
    }

    const parsed: Record<string, true> = {};
    for (const [rawKey, rawValue] of Object.entries(value as Record<string, unknown>)) {
        const key = normalizeDomain(rawKey);
        if (key === '' || rawValue !== true) {
            continue;
        }

        parsed[key] = true;
    }

    return parsed;
}

function normalizeExtensionSettings(value: Partial<ExtensionSettings>): ExtensionSettings {
    return {
        version: SETTINGS_VERSION,
        siteCustomizations: normalizeSiteCustomizations(value.siteCustomizations ?? []),
        closeTabAfterQueueByDomain: parseStoredCloseTabAfterQueueByDomain(value.closeTabAfterQueueByDomain),
        reactAllItemsInPostByDomain: parseStoredDomainBooleanPreferences(value.reactAllItemsInPostByDomain),
    };
}

function parseExtensionSettingsPayload(value: unknown): ExtensionSettings {
    if (!value || typeof value !== 'object') {
        return emptyExtensionSettings();
    }

    const row = value as Partial<ExtensionSettings>;

    return normalizeExtensionSettings({
        siteCustomizations: parseStoredSiteCustomizations(row.siteCustomizations),
        closeTabAfterQueueByDomain: parseStoredCloseTabAfterQueueByDomain(row.closeTabAfterQueueByDomain),
        reactAllItemsInPostByDomain: parseStoredDomainBooleanPreferences(row.reactAllItemsInPostByDomain),
    });
}

function serializeExtensionSettings(settings: ExtensionSettings): ExtensionSettings {
    return normalizeExtensionSettings(settings);
}

function hasMigrationSettings(settings: ExtensionSettings): boolean {
    return settings.siteCustomizations.length > 0
        || Object.keys(settings.closeTabAfterQueueByDomain).length > 0
        || Object.keys(settings.reactAllItemsInPostByDomain).length > 0;
}

function mergeSiteCustomizationsForMigration(
    localCustomizations: SiteCustomization[],
    remoteCustomizations: SiteCustomization[],
): SiteCustomization[] {
    const byDomain = new Map<string, SiteCustomization>();
    for (const customization of localCustomizations) {
        byDomain.set(customization.domain, customization);
    }

    for (const customization of remoteCustomizations) {
        byDomain.set(customization.domain, customization);
    }

    return normalizeSiteCustomizations(Array.from(byDomain.values()));
}

function mergeSettingsForMigration(localSettings: ExtensionSettings, remoteSettings: ExtensionSettings): ExtensionSettings {
    return normalizeExtensionSettings({
        siteCustomizations: mergeSiteCustomizationsForMigration(
            localSettings.siteCustomizations,
            remoteSettings.siteCustomizations,
        ),
        closeTabAfterQueueByDomain: {
            ...localSettings.closeTabAfterQueueByDomain,
            ...remoteSettings.closeTabAfterQueueByDomain,
        },
        reactAllItemsInPostByDomain: {
            ...localSettings.reactAllItemsInPostByDomain,
            ...remoteSettings.reactAllItemsInPostByDomain,
        },
    });
}

function readLocalStorage(keys: string[]): Promise<Record<string, unknown>> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.get(keys, (stored: Record<string, unknown>) => {
            if (chrome.runtime.lastError) {
                reject(new Error(chrome.runtime.lastError.message));
                return;
            }

            resolve(stored);
        });
    });
}

function writeLocalStorage(items: Record<string, unknown>): Promise<void> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.set(items, () => {
            if (chrome.runtime.lastError) {
                reject(new Error(chrome.runtime.lastError.message));
                return;
            }

            resolve();
        });
    });
}

function removeLocalStorage(keys: readonly string[]): Promise<void> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.remove([...keys], () => {
            if (chrome.runtime.lastError) {
                reject(new Error(chrome.runtime.lastError.message));
                return;
            }

            resolve();
        });
    });
}

async function clearLocalExtensionSettings(): Promise<void> {
    await removeLocalStorage(LOCAL_EXTENSION_SETTING_KEYS);
}

function cacheKeyForSnapshot(snapshot: LocalOptionsSnapshot): string {
    return storedOptionsCacheKey(snapshot.atlasDomain, snapshot.apiToken, snapshot.settingsUpdatedAt);
}

async function notifySettingsChanged(extraItems: Record<string, unknown> = {}): Promise<string> {
    const settingsUpdatedAt = String(Date.now());

    await writeLocalStorage({
        ...extraItems,
        [STORAGE_KEYS.settingsUpdatedAt]: settingsUpdatedAt,
    });

    return settingsUpdatedAt;
}

async function readLocalOptionsSnapshot(): Promise<LocalOptionsSnapshot> {
    const stored = await readLocalStorage([
        STORAGE_KEYS.atlasDomain,
        STORAGE_KEYS.apiToken,
        STORAGE_KEYS.settingsUpdatedAt,
        STORAGE_KEYS.matchRules,
        STORAGE_KEYS.referrerQueryParamsToStripByDomain,
        STORAGE_KEYS.siteCustomizations,
        STORAGE_KEYS.closeTabAfterQueueByDomain,
        STORAGE_KEYS.reactAllItemsInPostByDomain,
        STORAGE_KEYS.settingsMigrationByDomain,
    ]);

    const storedDomain = typeof stored.atlasDomain === 'string' ? normalizeDomain(stored.atlasDomain) : '';
    const atlasDomain = storedDomain !== '' ? storedDomain : DEFAULT_ATLAS_DOMAIN;
    const apiToken = typeof stored.apiToken === 'string' ? stored.apiToken.trim() : '';
    const storedSettingsUpdatedAt = stored[STORAGE_KEYS.settingsUpdatedAt];
    const settingsUpdatedAt = typeof storedSettingsUpdatedAt === 'string'
        ? storedSettingsUpdatedAt
        : '';
    const siteCustomizations = stored[STORAGE_KEYS.siteCustomizations] !== undefined
        ? parseStoredSiteCustomizations(stored[STORAGE_KEYS.siteCustomizations])
        : deriveSiteCustomizationsFromLegacyStorage(
            stored[STORAGE_KEYS.matchRules],
            stored[STORAGE_KEYS.referrerQueryParamsToStripByDomain],
        );
    const localSettings = normalizeExtensionSettings({
        siteCustomizations,
        closeTabAfterQueueByDomain: parseStoredCloseTabAfterQueueByDomain(
            stored[STORAGE_KEYS.closeTabAfterQueueByDomain],
        ),
        reactAllItemsInPostByDomain: parseStoredDomainBooleanPreferences(
            stored[STORAGE_KEYS.reactAllItemsInPostByDomain],
        ),
    });

    return {
        atlasDomain,
        apiToken,
        localSettings,
        hasLocalSettings: hasMigrationSettings(localSettings),
        settingsMigrationByDomain: parseStoredMigrationMap(stored[STORAGE_KEYS.settingsMigrationByDomain]),
        settingsUpdatedAt,
    };
}

export async function getStoredConnectionOptions(): Promise<StoredConnectionOptions> {
    const snapshot = await readLocalOptionsSnapshot();

    return {
        atlasDomain: snapshot.atlasDomain,
        apiToken: snapshot.apiToken,
    };
}

async function markSettingsMigratedForDomain(snapshot: LocalOptionsSnapshot): Promise<void> {
    await writeLocalStorage({
        [STORAGE_KEYS.settingsMigrationByDomain]: {
            ...snapshot.settingsMigrationByDomain,
            [snapshot.atlasDomain]: true,
        },
    });
    await clearLocalExtensionSettings();
}

function endpointFor(atlasDomain: string): string {
    return `${atlasDomain}/api/extension/settings`;
}

async function requestRemoteSettings(
    atlasDomain: string,
    apiToken: string,
    method: 'GET' | 'POST',
    settings?: ExtensionSettings,
): Promise<ExtensionSettings> {
    const endpoint = endpointFor(atlasDomain);
    const body = settings ? { settings: serializeExtensionSettings(settings) } : undefined;
    const runtimeResponse = await requestAtlasViaRuntime({
        endpoint,
        atlasDomain,
        apiToken,
        method,
        body,
    });

    if (runtimeResponse !== null) {
        if (!runtimeResponse.ok) {
            throw new Error('Unable to load extension settings from Atlas.');
        }

        return parseExtensionSettingsPayload((runtimeResponse.payload as RemoteSettingsPayload | null)?.settings);
    }

    if (typeof fetch !== 'function') {
        throw new Error('Unable to reach Atlas extension settings.');
    }

    const response = await fetch(endpoint, {
        method,
        headers: createAtlasApiHeaders(apiToken, method === 'POST'),
        ...createAtlasFetchAuthOptions(apiToken),
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    if (!response.ok) {
        throw new Error('Unable to load extension settings from Atlas.');
    }

    const payload = await response.json() as RemoteSettingsPayload;

    return parseExtensionSettingsPayload(payload.settings);
}

async function loadRemoteSettingsForSnapshot(
    snapshot: LocalOptionsSnapshot,
    options: { fallbackToLocalOnError?: boolean } = {},
): Promise<ExtensionSettings> {
    if (!hasAtlasApiAuth(snapshot.atlasDomain, snapshot.apiToken)) {
        return snapshot.localSettings;
    }

    try {
        const remoteSettings = await requestRemoteSettings(snapshot.atlasDomain, snapshot.apiToken, 'GET');
        if (!snapshot.hasLocalSettings || snapshot.settingsMigrationByDomain[snapshot.atlasDomain] === true) {
            return remoteSettings;
        }

        const mergedSettings = mergeSettingsForMigration(snapshot.localSettings, remoteSettings);
        const storedSettings = await requestRemoteSettings(snapshot.atlasDomain, snapshot.apiToken, 'POST', mergedSettings);
        await markSettingsMigratedForDomain(snapshot);

        return storedSettings;
    } catch (error) {
        if (options.fallbackToLocalOnError === true) {
            return snapshot.localSettings;
        }

        throw error;
    }
}

export async function getStoredOptions(options: { bypassCache?: boolean } = {}): Promise<StoredOptions> {
    const snapshot = await readLocalOptionsSnapshot();
    const cacheKey = cacheKeyForSnapshot(snapshot);

    if (options.bypassCache !== true) {
        const cachedOptions = getFreshStoredOptions(cacheKey);
        if (cachedOptions !== null) {
            return cachedOptions;
        }

        const activeLoad = getStoredOptionsLoad(cacheKey);
        if (activeLoad !== null) {
            return activeLoad;
        }
    }

    const cacheGeneration = getStoredOptionsCacheGeneration();
    const loadPromise = loadRemoteSettingsForSnapshot(snapshot, { fallbackToLocalOnError: true })
        .then((settings) => {
            const storedOptions = {
                atlasDomain: snapshot.atlasDomain,
                apiToken: snapshot.apiToken,
                ...settings,
            };

            if (getStoredOptionsCacheGeneration() === cacheGeneration) {
                rememberStoredOptions(cacheKey, storedOptions);
            }

            return storedOptions;
        })
        .finally(() => {
            forgetStoredOptionsLoad(loadPromise);
        });

    if (options.bypassCache !== true) {
        rememberStoredOptionsLoad(cacheKey, loadPromise);
    }

    return loadPromise;
}

async function saveConnectionOptions(atlasDomain: string, apiToken: string): Promise<void> {
    await writeLocalStorage({
        [STORAGE_KEYS.atlasDomain]: atlasDomain,
        [STORAGE_KEYS.apiToken]: apiToken.trim(),
    });
}

async function saveRemoteSettingsForConnection(
    atlasDomain: string,
    apiToken: string,
    settings: ExtensionSettings,
): Promise<ExtensionSettings> {
    if (!hasAtlasApiAuth(atlasDomain, apiToken)) {
        throw new Error('Set the API key before saving extension settings to Atlas.');
    }

    return requestRemoteSettings(atlasDomain, apiToken, 'POST', settings);
}

export async function saveStoredOptions(
    atlasDomain: string,
    apiToken: string,
    siteCustomizations: SiteCustomization[] = [],
): Promise<void> {
    const normalizedDomain = normalizeDomain(atlasDomain);
    const normalizedToken = apiToken.trim();
    const snapshot = await readLocalOptionsSnapshot();
    invalidateStoredOptionsCache();

    const connectionSnapshot: LocalOptionsSnapshot = {
        ...snapshot,
        atlasDomain: normalizedDomain,
        apiToken: normalizedToken,
    };
    const currentSettings = await loadRemoteSettingsForSnapshot(connectionSnapshot);
    const savedSettings = await saveRemoteSettingsForConnection(normalizedDomain, normalizedToken, {
        ...currentSettings,
        siteCustomizations: normalizeSiteCustomizations(siteCustomizations),
    });
    await saveConnectionOptions(normalizedDomain, normalizedToken);
    await clearLocalExtensionSettings();
    const settingsUpdatedAt = await notifySettingsChanged();
    rememberStoredOptions(storedOptionsCacheKey(normalizedDomain, normalizedToken, settingsUpdatedAt), {
        atlasDomain: normalizedDomain,
        apiToken: normalizedToken,
        ...savedSettings,
    });
}

export async function saveFullSettingsForCurrentConnection(settings: ExtensionSettings): Promise<void> {
    const { atlasDomain, apiToken } = await getStoredConnectionOptions();
    invalidateStoredOptionsCache();
    const savedSettings = await saveRemoteSettingsForConnection(atlasDomain, apiToken, settings);
    const settingsUpdatedAt = await notifySettingsChanged();
    rememberStoredOptions(storedOptionsCacheKey(atlasDomain, apiToken, settingsUpdatedAt), {
        atlasDomain,
        apiToken,
        ...savedSettings,
    });
}

export {
    getCloseTabAfterQueueByDomain,
    getCloseTabAfterQueuePreferenceForHostname,
    getReactAllItemsInPostByDomain,
    getReactAllItemsInPostPreferenceForHostname,
    setCloseTabAfterQueuePreferenceForHostname,
    setReactAllItemsInPostPreferenceForHostname,
    setSiteCustomizationEnabledForDomain,
} from './atlas-option-preferences';
