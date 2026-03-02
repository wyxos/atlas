/* global chrome */
import { onMounted, onUnmounted, ref } from 'vue';
import {
    GET_EXTENSION_RELOAD_STATE_EVENT,
    isExtensionReloadRequiredMessage,
    type ExtensionReloadStateResponse,
    type GetExtensionReloadStateMessage,
} from './reload-required-message';

function fetchExtensionReloadState(): Promise<boolean> {
    return new Promise((resolve) => {
        if (!chrome.runtime?.sendMessage) {
            resolve(false);
            return;
        }

        const message: GetExtensionReloadStateMessage = {
            type: GET_EXTENSION_RELOAD_STATE_EVENT,
        };

        chrome.runtime.sendMessage(message, (response: unknown) => {
            if (chrome.runtime.lastError) {
                resolve(false);
                return;
            }

            const payload = response as Partial<ExtensionReloadStateResponse> | null;
            resolve(payload?.requiresReload === true);
        });
    });
}

export function useExtensionReloadRequirement() {
    const requiresReload = ref(false);

    function onRuntimeMessage(message: unknown): void {
        if (isExtensionReloadRequiredMessage(message)) {
            requiresReload.value = true;
        }
    }

    function reloadExtension(): void {
        chrome.runtime.reload();
    }

    onMounted(() => {
        void fetchExtensionReloadState().then((value) => {
            if (value) {
                requiresReload.value = true;
            }
        });

        chrome.runtime?.onMessage?.addListener(onRuntimeMessage);
    });

    onUnmounted(() => {
        chrome.runtime?.onMessage?.removeListener(onRuntimeMessage);
    });

    return {
        requiresReload,
        reloadExtension,
    };
}
