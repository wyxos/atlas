import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('atlas-request-failure-feedback', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.resetModules();
        vi.clearAllMocks();
        document.body.innerHTML = '';
        document.documentElement.querySelectorAll('#atlas-extension-request-failure-toast-container')
            .forEach((element) => element.remove());
    });

    it('logs failures, keeps a single toast active, and clears it on demand or timeout', async () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
        const {
            clearAtlasRequestFailureFeedback,
            reportAtlasRequestFailure,
        } = await import('./atlas-request-failure-feedback');

        reportAtlasRequestFailure({
            endpoint: '/api/extension/ping',
            method: 'GET',
            requestPayload: null,
            responsePayload: { message: 'boom' },
            status: 'network_error',
        });

        expect(consoleError).toHaveBeenCalledWith(
            '[Atlas Extension] Request failed',
            expect.objectContaining({
                endpoint: '/api/extension/ping',
                method: 'GET',
                status: 'network_error',
            }),
        );

        let container = document.getElementById('atlas-extension-request-failure-toast-container');
        expect(container).toBeInstanceOf(HTMLDivElement);
        expect(container?.querySelectorAll('[data-atlas-request-failure-toast="1"]')).toHaveLength(1);
        expect(container?.textContent).toContain('Atlas request failed (network error). Check the console for details.');

        reportAtlasRequestFailure({
            endpoint: '/api/extension/reactions',
            method: 'POST',
            requestPayload: { file_id: 7 },
            responsePayload: { message: 'bad request' },
            status: 422,
        });

        container = document.getElementById('atlas-extension-request-failure-toast-container');
        expect(container?.querySelectorAll('[data-atlas-request-failure-toast="1"]')).toHaveLength(1);
        expect(container?.textContent).toContain('Atlas request failed (422). Check the console for details.');

        vi.advanceTimersByTime(5_000);
        expect(document.querySelector('[data-atlas-request-failure-toast="1"]')).toBeNull();

        clearAtlasRequestFailureFeedback();
        expect(document.getElementById('atlas-extension-request-failure-toast-container')).toBeNull();
    });
});
