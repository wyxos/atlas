import { ref, type Ref } from 'vue';
import {
    type CloseTabAfterQueueMode,
    getCloseTabAfterQueuePreferenceForHostname,
    setCloseTabAfterQueuePreferenceForHostname,
    STORAGE_KEYS,
} from '../atlas-options';

type CloseTabAfterQueueState = {
    mode: Ref<CloseTabAfterQueueMode>;
    saving: Ref<boolean>;
    loadPromise: Promise<void> | null;
};

const statesByHostname = new Map<string, CloseTabAfterQueueState>();
let storageListenerInstalled = false;

function normalizeHostname(hostname: string): string {
    return hostname.trim().toLowerCase();
}

function ensureState(hostname: string): CloseTabAfterQueueState {
    const existing = statesByHostname.get(hostname);
    if (existing) {
        return existing;
    }

    const state: CloseTabAfterQueueState = {
        mode: ref('off'),
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
            state.mode.value = await getCloseTabAfterQueuePreferenceForHostname(hostname);
        } catch {
            state.mode.value = 'off';
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
        if (areaName !== 'local' || !(STORAGE_KEYS.closeTabAfterQueueByDomain in changes)) {
            return;
        }

        for (const hostname of statesByHostname.keys()) {
            void refreshPreference(hostname);
        }
    });
}

export function useCloseTabAfterQueuePreference(hostname: string): {
    mode: Ref<CloseTabAfterQueueMode>;
    saving: Ref<boolean>;
    cycleMode: () => Promise<void>;
} {
    const normalizedHostname = normalizeHostname(hostname);
    const state = ensureState(normalizedHostname);

    installStorageListener();
    void refreshPreference(normalizedHostname);

    async function cycleMode(): Promise<void> {
        if (normalizedHostname === '' || state.saving.value) {
            return;
        }

        state.saving.value = true;

        try {
            await refreshPreference(normalizedHostname);

            const nextMode: CloseTabAfterQueueMode = state.mode.value === 'off'
                ? 'queued'
                : state.mode.value === 'queued'
                    ? 'completed'
                    : 'off';
            state.mode.value = nextMode;

            try {
                await setCloseTabAfterQueuePreferenceForHostname(normalizedHostname, nextMode);
            } catch {
                await refreshPreference(normalizedHostname);
            }
        } finally {
            state.saving.value = false;
        }
    }

    return {
        mode: state.mode,
        saving: state.saving,
        cycleMode,
    };
}
