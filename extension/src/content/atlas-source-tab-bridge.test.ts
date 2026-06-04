import { beforeEach, describe, expect, it, vi } from 'vitest';

function setLocation(url: string): void {
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: new URL(url) as unknown as Location,
    });
}

async function flushBridgeInstall(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
}

function createChromeMock(stored: Record<string, unknown> = {}) {
    return {
        runtime: {
            lastError: null,
            sendMessage: vi.fn((_: unknown, callback?: () => void) => {
                callback?.();
            }),
        },
        storage: {
            local: {
                get: vi.fn((_: string[], callback: (stored: Record<string, unknown>) => void) => {
                    callback(stored);
                }),
            },
        },
    };
}

describe('installAtlasSourceTabBridge', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.documentElement.removeAttribute('data-atlas-extension-source-tab-bridge');
        setLocation('https://atlas.test/downloads');
    });

    it('bridges Atlas page source-tab requests to the extension runtime', async () => {
        const chromeMock = createChromeMock();
        vi.stubGlobal('chrome', chromeMock);

        const {
            ATLAS_SOURCE_TAB_BRIDGE_ATTR,
            installAtlasSourceTabBridge,
        } = await import('./atlas-source-tab-bridge');

        const cleanup = installAtlasSourceTabBridge();
        await flushBridgeInstall();

        expect(document.documentElement.getAttribute(ATLAS_SOURCE_TAB_BRIDGE_ATTR)).toBe('1');

        window.dispatchEvent(new MessageEvent('message', {
            source: window,
            origin: window.location.origin,
            data: {
                type: 'ATLAS_OPEN_SOURCE_TABS',
                urls: [
                    ' https://www.deviantart.com/artist/art/first ',
                    'javascript:alert(1)',
                    'https://www.deviantart.com/artist/art/second',
                    'https://www.deviantart.com/artist/art/second',
                ],
            },
        }));

        expect(chromeMock.runtime.sendMessage).toHaveBeenCalledWith({
            type: 'ATLAS_OPEN_SOURCE_TABS',
            urls: [
                'https://www.deviantart.com/artist/art/first',
                'https://www.deviantart.com/artist/art/second',
            ],
        }, expect.any(Function));

        cleanup();

        expect(document.documentElement.getAttribute(ATLAS_SOURCE_TAB_BRIDGE_ATTR)).toBeNull();
    });

    it('bridges the configured Atlas app domain instead of a hardcoded host list', async () => {
        setLocation('https://mydomain.com/downloads');
        const chromeMock = createChromeMock({
            atlasDomain: 'https://mydomain.com',
        });
        vi.stubGlobal('chrome', chromeMock);

        const {
            ATLAS_SOURCE_TAB_BRIDGE_ATTR,
            installAtlasSourceTabBridge,
        } = await import('./atlas-source-tab-bridge');

        const cleanup = installAtlasSourceTabBridge();
        await flushBridgeInstall();

        expect(document.documentElement.getAttribute(ATLAS_SOURCE_TAB_BRIDGE_ATTR)).toBe('1');

        cleanup();

        expect(document.documentElement.getAttribute(ATLAS_SOURCE_TAB_BRIDGE_ATTR)).toBeNull();
    });

    it('does not install the bridge outside Atlas app hosts', async () => {
        setLocation('https://www.deviantart.com/');
        const chromeMock = createChromeMock({
            atlasDomain: 'https://mydomain.com',
        });
        vi.stubGlobal('chrome', chromeMock);

        const {
            ATLAS_SOURCE_TAB_BRIDGE_ATTR,
            installAtlasSourceTabBridge,
        } = await import('./atlas-source-tab-bridge');

        installAtlasSourceTabBridge();
        await flushBridgeInstall();

        window.dispatchEvent(new MessageEvent('message', {
            source: window,
            origin: window.location.origin,
            data: {
                type: 'ATLAS_OPEN_SOURCE_TABS',
                urls: ['https://www.deviantart.com/artist/art/first'],
            },
        }));

        expect(document.documentElement.getAttribute(ATLAS_SOURCE_TAB_BRIDGE_ATTR)).toBeNull();
        expect(chromeMock.runtime.sendMessage).not.toHaveBeenCalled();
    });
});
