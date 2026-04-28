import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

describe('submitBadgeReaction civitai overrides', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        history.replaceState({}, '', '/images/9101002');
    });

    it('includes listing metadata overrides for civitai single-image reactions', async () => {
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            siteCustomizations: [],
        });

        vi.stubGlobal('fetch', vi.fn());
        const runtimeSendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
            const typed = payload as { type?: string };
            if (typed.type === 'ATLAS_GET_URL_COOKIES') {
                callback({ cookies: [] });
                return;
            }

            if (typed.type === 'ATLAS_SUBMIT_REACTION') {
                callback({
                    ok: true,
                    status: 200,
                    payload: {
                        reaction: 'like',
                        exists: true,
                        download: {
                            requested: false,
                            transfer_id: null,
                            status: null,
                            progress_percent: null,
                        },
                    },
                });
                return;
            }

            callback(null);
        });
        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: runtimeSendMessage,
            },
        });

        const { submitBadgeReaction } = await import('./reaction-submit');
        const image = document.createElement('img');
        image.src = 'https://image.civitai.com/token/guid/original=true,quality=90/example.jpeg';

        await submitBadgeReaction(image, 'like', {
            listingMetadataOverrides: {
                postId: 9202001,
                username: 'exampleCreator',
                resource_containers: [
                    {
                        type: 'LoRA',
                        modelId: 9303002,
                        modelVersionId: 9404002,
                        referrerUrl: 'https://civitai.com/models/9303002/example-lora?modelVersionId=9404002',
                    },
                ],
            },
        });

        const submitCall = runtimeSendMessage.mock.calls[1] as [Record<string, unknown>, (response: unknown) => void];
        const body = submitCall[0].body as Record<string, unknown>;

        expect(body.listing_metadata_overrides).toEqual({
            postId: 9202001,
            username: 'exampleCreator',
            resource_containers: [
                {
                    type: 'LoRA',
                    modelId: 9303002,
                    modelVersionId: 9404002,
                    referrerUrl: 'https://civitai.com/models/9303002/example-lora?modelVersionId=9404002',
                },
            ],
        });
    });
});
