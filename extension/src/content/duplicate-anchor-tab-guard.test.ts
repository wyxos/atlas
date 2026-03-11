import { beforeEach, describe, expect, it, vi } from 'vitest';

type RuntimeMessage =
    | { type: 'ATLAS_GET_OPEN_COMPARABLE_URL_COUNTS' }
    | { type?: string };

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

describe('duplicate-anchor-tab-guard', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        history.replaceState({}, '', '/post#image-1');
    });

    it('blocks eligible duplicate middle-clicks and shows the dialog', async () => {
        const currentUrl = `${window.location.origin}/post#image-1`;
        const runtimeSendMessage = vi.fn((message: RuntimeMessage, callback: (response: unknown) => void) => {
            if (message.type === 'ATLAS_GET_OPEN_COMPARABLE_URL_COUNTS') {
                callback({
                    counts: {
                        [currentUrl]: 2,
                    },
                });
            }
        });
        vi.stubGlobal('chrome', {
            runtime: {
                sendMessage: runtimeSendMessage,
            },
        });

        const { createDuplicateAnchorTabGuard } = await import('./duplicate-anchor-tab-guard');
        const guard = createDuplicateAnchorTabGuard();
        await guard.refreshSnapshot();

        const anchor = document.createElement('a');
        anchor.href = currentUrl;
        anchor.textContent = 'Open existing';
        document.body.appendChild(anchor);

        const event = new MouseEvent('auxclick', { bubbles: true, cancelable: true, button: 1 });
        anchor.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(document.body.textContent).toContain('This link is already open in another tab.');

        guard.destroy();
    });

    it('allows different hashes and plain-root urls even when they are open elsewhere', async () => {
        const currentUrl = `${window.location.origin}/post#image-1`;
        const alternateHashUrl = `${window.location.origin}/post#image-2`;
        const runtimeSendMessage = vi.fn((message: RuntimeMessage, callback: (response: unknown) => void) => {
            if (message.type === 'ATLAS_GET_OPEN_COMPARABLE_URL_COUNTS') {
                callback({
                    counts: {
                        [alternateHashUrl]: 1,
                    },
                });
            }
        });
        vi.stubGlobal('chrome', {
            runtime: {
                sendMessage: runtimeSendMessage,
            },
        });

        const { createDuplicateAnchorTabGuard } = await import('./duplicate-anchor-tab-guard');
        const guard = createDuplicateAnchorTabGuard();
        await guard.refreshSnapshot();

        const differentHashAnchor = document.createElement('a');
        differentHashAnchor.href = currentUrl;
        differentHashAnchor.textContent = 'Different hash';
        document.body.appendChild(differentHashAnchor);

        const differentHashEvent = new MouseEvent('auxclick', { bubbles: true, cancelable: true, button: 1 });
        differentHashAnchor.dispatchEvent(differentHashEvent);

        expect(differentHashEvent.defaultPrevented).toBe(false);

        const rootAnchor = document.createElement('a');
        rootAnchor.href = 'https://x.com/';
        rootAnchor.textContent = 'Root';
        document.body.appendChild(rootAnchor);

        const rootEvent = new MouseEvent('auxclick', { bubbles: true, cancelable: true, button: 1 });
        rootAnchor.dispatchEvent(rootEvent);

        expect(rootEvent.defaultPrevented).toBe(false);
        expect(document.querySelector('[data-atlas-duplicate-tab-dialog="1"]')).toBeNull();

        guard.destroy();
    });

    it('does not block when the matching url is only open in the current tab', async () => {
        const currentUrl = `${window.location.origin}/post#image-1`;
        const runtimeSendMessage = vi.fn((message: RuntimeMessage, callback: (response: unknown) => void) => {
            if (message.type === 'ATLAS_GET_OPEN_COMPARABLE_URL_COUNTS') {
                callback({
                    counts: {
                        [currentUrl]: 1,
                    },
                });
            }
        });
        vi.stubGlobal('chrome', {
            runtime: {
                sendMessage: runtimeSendMessage,
            },
        });

        const { createDuplicateAnchorTabGuard } = await import('./duplicate-anchor-tab-guard');
        const guard = createDuplicateAnchorTabGuard();
        await guard.refreshSnapshot();

        const anchor = document.createElement('a');
        anchor.href = currentUrl;
        anchor.textContent = 'Current tab';
        document.body.appendChild(anchor);

        const event = new MouseEvent('auxclick', { bubbles: true, cancelable: true, button: 1 });
        anchor.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(document.querySelector('[data-atlas-duplicate-tab-dialog="1"]')).toBeNull();

        guard.destroy();
    });

    it('applies changed counts from tab presence messages without re-snapshotting', async () => {
        const currentUrl = `${window.location.origin}/post#image-1`;
        const runtimeSendMessage = vi.fn((message: RuntimeMessage, callback: (response: unknown) => void) => {
            if (message.type === 'ATLAS_GET_OPEN_COMPARABLE_URL_COUNTS') {
                callback({ counts: {} });
            }
        });
        vi.stubGlobal('chrome', {
            runtime: {
                sendMessage: runtimeSendMessage,
            },
        });

        const { createDuplicateAnchorTabGuard } = await import('./duplicate-anchor-tab-guard');
        const guard = createDuplicateAnchorTabGuard();
        await guard.refreshSnapshot();

        const anchor = document.createElement('a');
        anchor.href = currentUrl;
        anchor.textContent = 'Open existing';
        document.body.appendChild(anchor);

        const beforeRefreshEvent = new MouseEvent('auxclick', { bubbles: true, cancelable: true, button: 1 });
        anchor.dispatchEvent(beforeRefreshEvent);
        expect(beforeRefreshEvent.defaultPrevented).toBe(false);

        guard.handleTabPresenceChanged({
            urls: [currentUrl],
            counts: {
                [currentUrl]: 2,
            },
        });

        const afterRefreshEvent = new MouseEvent('auxclick', { bubbles: true, cancelable: true, button: 1 });
        anchor.dispatchEvent(afterRefreshEvent);
        expect(afterRefreshEvent.defaultPrevented).toBe(true);
        expect(runtimeSendMessage).toHaveBeenCalledTimes(1);

        guard.destroy();
    });

    it('allows middle-clicks while the snapshot is still loading', async () => {
        const currentUrl = `${window.location.origin}/post#image-1`;
        const runtimeSendMessage = vi.fn((message: RuntimeMessage, callback: (response: unknown) => void) => {
            if (message.type === 'ATLAS_GET_OPEN_COMPARABLE_URL_COUNTS') {
                setTimeout(() => {
                    callback({
                        counts: {
                            [currentUrl]: 2,
                        },
                    });
                }, 10);
            }
        });
        vi.stubGlobal('chrome', {
            runtime: {
                sendMessage: runtimeSendMessage,
            },
        });

        const { createDuplicateAnchorTabGuard } = await import('./duplicate-anchor-tab-guard');
        const guard = createDuplicateAnchorTabGuard();

        const anchor = document.createElement('a');
        anchor.href = currentUrl;
        anchor.textContent = 'Open existing';
        document.body.appendChild(anchor);

        const event = new MouseEvent('auxclick', { bubbles: true, cancelable: true, button: 1 });
        anchor.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);

        await flushPromises();
        guard.destroy();
    });
});
