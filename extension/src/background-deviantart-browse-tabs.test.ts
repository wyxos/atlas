import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredConnectionOptions = vi.fn();

vi.mock('./atlas-options', () => ({
    getStoredConnectionOptions: mockGetStoredConnectionOptions,
}));

function createChromeMock() {
    return {
        runtime: {
            lastError: null,
        },
        tabs: {
            create: vi.fn((_: { url?: string }, callback?: () => void) => {
                callback?.();
            }),
        },
    };
}

describe('handleOpenDeviantArtUsernameTabRuntimeMessage', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        mockGetStoredConnectionOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
        });
    });

    it('creates a new Atlas browser tab for a DeviantArt username browse request', async () => {
        const chromeMock = createChromeMock();
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            browse_url: 'https://atlas.test/browse',
            tab: {
                id: 48,
                label: 'DeviantArt Images: User velvetemberartist - 1',
            },
        }), { status: 200 }));
        vi.stubGlobal('chrome', chromeMock);
        vi.stubGlobal('fetch', fetchMock);

        const { handleOpenDeviantArtUsernameTabRuntimeMessage } = await import('./background-deviantart-browse-tabs');

        const response = await new Promise((resolve) => {
            const handled = handleOpenDeviantArtUsernameTabRuntimeMessage({
                type: 'ATLAS_OPEN_DEVIANTART_USERNAME_TAB',
                username: 'velvetemberartist',
            }, resolve);

            expect(handled).toBe(true);
        });

        expect(mockGetStoredConnectionOptions).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith('https://atlas.test/api/extension/browse-tabs/deviantart-user', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Atlas-Api-Key': 'test-api-token',
            },
            body: JSON.stringify({
                username: 'velvetemberartist',
            }),
        });
        expect(chromeMock.tabs.create).toHaveBeenCalledWith({ url: 'https://atlas.test/browse' }, expect.any(Function));
        expect(response).toEqual({
            ok: true,
            status: 200,
            payload: {
                browse_url: 'https://atlas.test/browse',
                tab: {
                    id: 48,
                    label: 'DeviantArt Images: User velvetemberartist - 1',
                },
            },
        });
    });
});
