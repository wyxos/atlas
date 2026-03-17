import { beforeEach, describe, expect, it, vi } from 'vitest';

type RuntimeListener = (message: unknown) => void;

describe('reaction-badge-tab-runtime', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('requests similar-domain and total tab counts from runtime', async () => {
        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                onMessage: {
                    addListener: vi.fn(),
                },
                sendMessage: vi.fn((message: unknown, callback?: (response: unknown) => void) => {
                    const payload = message as { type?: string };
                    if (payload.type === 'ATLAS_GET_TAB_COUNT') {
                        callback?.({
                            count: 5,
                            similarDomainCount: 2,
                        });
                        return;
                    }

                    callback?.(null);
                }),
            },
        });

        const { requestTabCount } = await import('./reaction-badge-tab-runtime');

        await expect(requestTabCount()).resolves.toEqual({
            similarDomainCount: 2,
            totalCount: 5,
        });
    });

    it('notifies listeners with similar-domain and total tab counts', async () => {
        let runtimeListener: RuntimeListener | null = null;
        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                onMessage: {
                    addListener: vi.fn((listener: RuntimeListener) => {
                        runtimeListener = listener;
                    }),
                },
                sendMessage: vi.fn(),
            },
        });

        const { subscribeToTabCountChanged } = await import('./reaction-badge-tab-runtime');
        const listener = vi.fn();

        subscribeToTabCountChanged(listener);
        runtimeListener?.({
            type: 'ATLAS_TAB_COUNT_CHANGED',
            count: 8,
            similarDomainCount: 3,
        });

        expect(listener).toHaveBeenCalledWith({
            similarDomainCount: 3,
            totalCount: 8,
        });
    });
});
