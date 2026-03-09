import { ref, type Ref } from 'vue';
import {
    getReactAllItemsInPostPreferenceForHostname,
    setReactAllItemsInPostPreferenceForHostname,
    STORAGE_KEYS,
} from '../atlas-options';

type ReactAllItemsInPostState = {
    enabled: Ref<boolean>;
    saving: Ref<boolean>;
    loadPromise: Promise<void> | null;
};

const statesByHostname = new Map<string, ReactAllItemsInPostState>();
let storageListenerInstalled = false;

function normalizeHostname(hostname: string): string {
    return hostname.trim().toLowerCase();
}

function ensureState(hostname: string): ReactAllItemsInPostState {
    const existing = statesByHostname.get(hostname);
    if (existing) {
        return existing;
    }

    const state: ReactAllItemsInPostState = {
        enabled: ref(false),
        saving: ref(false),
        loadPromise: null,
    };
    statesByHostname.set(hostname, state);
    return state;
}

async function refreshPreference(hostname: string): Promise<void> {
    if (hostname === '') {
        return;
    }

    const state = ensureState(hostname);
    if (state.loadPromise !== null) {
        return state.loadPromise;
    }

    state.loadPromise = (async () => {
        try {
            state.enabled.value = await getReactAllItemsInPostPreferenceForHostname(hostname);
        } catch {
            state.enabled.value = false;
        } finally {
            state.loadPromise = null;
        }
    })();

    return state.loadPromise;
}

function installStorageListener(): void {
    if (storageListenerInstalled || typeof chrome === 'undefined' || !chrome.storage?.onChanged) {
        return;
    }

    storageListenerInstalled = true;
    chrome.storage.onChanged.addListener((changes: Record<string, { newValue?: unknown }>, areaName: string) => {
        if (areaName !== 'local') {
            return;
        }

        if (!(STORAGE_KEYS.reactAllItemsInPostByDomain in changes)
            && !(STORAGE_KEYS.reactAllItemsInPostEnabled in changes)) {
            return;
        }

        for (const hostname of statesByHostname.keys()) {
            void refreshPreference(hostname);
        }
    });
}

export function useReactAllItemsInPostPreference(hostname: string): {
    enabled: Ref<boolean>;
    saving: Ref<boolean>;
    toggle: () => Promise<void>;
} {
    const normalizedHostname = normalizeHostname(hostname);
    const state = ensureState(normalizedHostname);

    installStorageListener();
    void refreshPreference(normalizedHostname);

    async function toggle(): Promise<void> {
        if (normalizedHostname === '' || state.saving.value) {
            return;
        }

        state.saving.value = true;

        try {
            await refreshPreference(normalizedHostname);

            const nextEnabled = !state.enabled.value;
            state.enabled.value = nextEnabled;

            try {
                await setReactAllItemsInPostPreferenceForHostname(normalizedHostname, nextEnabled);
            } catch {
                state.enabled.value = !nextEnabled;
            }
        } finally {
            state.saving.value = false;
        }
    }

    return {
        enabled: state.enabled,
        saving: state.saving,
        toggle,
    };
}
