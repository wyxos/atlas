/* global chrome */
import { createApp, defineComponent, h, onMounted, onUnmounted, ref } from 'vue';
import ExtensionReloadRequiredToast from '../ExtensionReloadRequiredToast.vue';
import {
    GET_EXTENSION_RELOAD_STATE_EVENT,
    isExtensionReloadRequiredMessage,
    type ExtensionReloadStateResponse,
    type GetExtensionReloadStateMessage,
} from '../reload-required-message';

const RELOAD_TOAST_HOST_ATTR = 'data-atlas-extension-reload-toast-host';

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

const ReloadRequiredToastHostApp = defineComponent({
    name: 'ReloadRequiredToastHostApp',
    setup() {
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

        return () => h(ExtensionReloadRequiredToast, {
            open: requiresReload.value,
            onReload: reloadExtension,
        });
    },
});

export function mountReloadRequiredToastHost(): void {
    if (document.querySelector(`[${RELOAD_TOAST_HOST_ATTR}]`)) {
        return;
    }

    const host = document.createElement('div');
    host.setAttribute(RELOAD_TOAST_HOST_ATTR, '1');
    host.style.position = 'fixed';
    host.style.inset = '0';
    host.style.pointerEvents = 'none';
    host.style.zIndex = '2147483647';

    const mountTarget = document.body ?? document.documentElement;
    mountTarget.appendChild(host);

    const app = createApp(ReloadRequiredToastHostApp);
    app.mount(host);
}
