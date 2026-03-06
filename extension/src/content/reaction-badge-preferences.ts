import {
    getCloseTabAfterQueuePreferenceForHostname,
    getReactAllItemsInPostPreference,
    setCloseTabAfterQueuePreferenceForHostname,
    setReactAllItemsInPostPreference,
} from '../atlas-options';

export async function loadReactAllItemsInPostPreference(): Promise<boolean> {
    try {
        return await getReactAllItemsInPostPreference();
    } catch {
        return false;
    }
}

export async function toggleReactAllItemsInPostPreference(currentValue: boolean): Promise<boolean> {
    const nextValue = !currentValue;

    try {
        await setReactAllItemsInPostPreference(nextValue);
        return nextValue;
    } catch {
        return currentValue;
    }
}

export async function loadCloseTabAfterQueuePreference(hostname: string): Promise<boolean> {
    if (hostname === '') {
        return false;
    }

    try {
        return await getCloseTabAfterQueuePreferenceForHostname(hostname);
    } catch {
        return false;
    }
}

export async function saveCloseTabAfterQueuePreference(hostname: string, enabled: boolean): Promise<boolean> {
    if (hostname === '') {
        return false;
    }

    try {
        await setCloseTabAfterQueuePreferenceForHostname(hostname, enabled);
        return true;
    } catch {
        return false;
    }
}
