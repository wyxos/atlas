import {
    type CloseTabAfterQueueByDomain,
    type CloseTabAfterQueueMode,
    getStoredOptions,
    normalizePreferenceDomainKey,
    saveFullSettingsForCurrentConnection,
    type ReactAllItemsInPostByDomain,
} from './atlas-options';
import {
    createEmptySiteCustomization,
    resolveStoredSiteCustomizationForHostname,
} from './site-customizations';

export async function setSiteCustomizationEnabledForDomain(domain: string, enabled: boolean): Promise<void> {
    const normalizedDomain = normalizePreferenceDomainKey(domain);
    if (normalizedDomain === '') {
        return;
    }

    const stored = await getStoredOptions({ bypassCache: true });
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

    await saveFullSettingsForCurrentConnection({
        ...stored,
        siteCustomizations: nextSiteCustomizations,
    });
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

    const stored = await getStoredOptions({ bypassCache: true });
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

    const stored = await getStoredOptions({ bypassCache: true });

    await saveFullSettingsForCurrentConnection({
        ...stored,
        reactAllItemsInPostByDomain: {
            ...stored.reactAllItemsInPostByDomain,
            [key]: enabled,
        },
    });
}
