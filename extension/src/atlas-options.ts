/* global chrome */
import { createAtlasApiHeaders, createAtlasFetchAuthOptions, hasAtlasApiAuth } from './atlas-auth';
import { requestAtlasViaRuntime } from './atlas-runtime-request';
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

async function notifySettingsChanged(extraItems: Record<string, unknown> = {}): Promise<void> {
    await writeLocalStorage({
        ...extraItems,
        [STORAGE_KEYS.settingsUpdatedAt]: String(Date.now()),
    });
}

async function readLocalOptionsSnapshot(): Promise<LocalOptionsSnapshot> {
    const stored = await readLocalStorage([
        STORAGE_KEYS.atlasDomain,
        STORAGE_KEYS.apiToken,
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

export async function getStoredOptions(): Promise<StoredOptions> {
    const snapshot = await readLocalOptionsSnapshot();
    const settings = await loadRemoteSettingsForSnapshot(snapshot, { fallbackToLocalOnError: true });

    return {
        atlasDomain: snapshot.atlasDomain,
        apiToken: snapshot.apiToken,
        ...settings,
    };
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

    const connectionSnapshot: LocalOptionsSnapshot = {
        ...snapshot,
        atlasDomain: normalizedDomain,
        apiToken: normalizedToken,
    };
    const currentSettings = await loadRemoteSettingsForSnapshot(connectionSnapshot);
    await saveRemoteSettingsForConnection(normalizedDomain, normalizedToken, {
        ...currentSettings,
        siteCustomizations: normalizeSiteCustomizations(siteCustomizations),
    });
    await saveConnectionOptions(normalizedDomain, normalizedToken);
    await clearLocalExtensionSettings();
    await notifySettingsChanged();
}

async function saveFullSettingsForCurrentConnection(settings: ExtensionSettings): Promise<void> {
    const { atlasDomain, apiToken } = await getStoredConnectionOptions();
    await saveRemoteSettingsForConnection(atlasDomain, apiToken, settings);
    await notifySettingsChanged();
}

export async function setSiteCustomizationEnabledForDomain(domain: string, enabled: boolean): Promise<void> {
    const normalizedDomain = normalizePreferenceDomainKey(domain);
    if (normalizedDomain === '') {
        return;
    }

    const stored = await getStoredOptions();
    const existingCustomization = resolveStoredSiteCustomizationForHostname(stored.siteCustomizations, normalizedDomain);
    const nextSiteCustomizations = existingCustomization === null
        ? [...stored.siteCustomizations, {
            ...createEmptySiteCustomization(normalizedDomain),
            enabled,
        }]
        : stored.siteCustomizations.map((customization) => customization.domain === existingCustomization.domain
            ? {
                ...customization,
                enabled,
            }
            : customization);

    await saveRemoteSettingsForConnection(stored.atlasDomain, stored.apiToken, {
        ...stored,
        siteCustomizations: nextSiteCustomizations,
    });
    await notifySettingsChanged();
}

export async function getCloseTabAfterQueueByDomain(): Promise<CloseTabAfterQueueByDomain> {
    const stored = await getStoredOptions();

    return stored.closeTabAfterQueueByDomain;
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

    const stored = await getStoredOptions();
    const nextPreferences: CloseTabAfterQueueByDomain = { ...stored.closeTabAfterQueueByDomain };
    if (mode === 'queued' || mode === 'completed') {
        nextPreferences[key] = mode;
    } else {
        delete nextPreferences[key];
    }

    await saveFullSettingsForCurrentConnection({
        ...stored,
        closeTabAfterQueueByDomain: nextPreferences,
    });
}

export async function getReactAllItemsInPostByDomain(): Promise<ReactAllItemsInPostByDomain> {
    const stored = await getStoredOptions();

    return stored.reactAllItemsInPostByDomain;
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

    const stored = await getStoredOptions();

    await saveFullSettingsForCurrentConnection({
        ...stored,
        reactAllItemsInPostByDomain: {
            ...stored.reactAllItemsInPostByDomain,
            [key]: enabled,
        },
    });
}
