export const EXTENSION_RELOAD_REQUIRED_EVENT = 'ATLAS_EXTENSION_RELOAD_REQUIRED' as const;
export const GET_EXTENSION_RELOAD_STATE_EVENT = 'ATLAS_GET_EXTENSION_RELOAD_STATE' as const;

export type ExtensionReloadRequiredMessage = {
    type: typeof EXTENSION_RELOAD_REQUIRED_EVENT;
};

export type GetExtensionReloadStateMessage = {
    type: typeof GET_EXTENSION_RELOAD_STATE_EVENT;
};

export type ExtensionReloadStateResponse = {
    requiresReload: boolean;
};

export function isExtensionReloadRequiredMessage(message: unknown): message is ExtensionReloadRequiredMessage {
    if (typeof message !== 'object' || message === null) {
        return false;
    }

    return (message as { type?: unknown }).type === EXTENSION_RELOAD_REQUIRED_EVENT;
}
