import { describe, it, expect, vi, beforeEach } from 'vitest';
import { installAxiosCsrfRetryInterceptor } from './axiosCsrfRetry';

function createAxiosMock() {
    const axios = {
        get: vi.fn(),
        request: vi.fn(),
        interceptors: {
            response: {
                use: vi.fn(),
            },
        },
    };

    return axios;
}

describe('installAxiosCsrfRetryInterceptor', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('refreshes csrf cookie and retries once on 419', async () => {
        const axios = createAxiosMock();
        axios.get.mockResolvedValueOnce({ status: 204 });
        axios.request.mockResolvedValueOnce({ data: { ok: true } });

        installAxiosCsrfRetryInterceptor(axios as any, { refreshUrl: '/api/csrf' });

        const [, onRejected] = (axios.interceptors.response.use as any).mock.calls[0];

        const error = {
            response: { status: 419 },
            config: { url: '/api/files/1/reaction', method: 'post', headers: {} },
        };

        const result = await onRejected(error);

        expect(axios.get).toHaveBeenCalledWith('/api/csrf', expect.any(Object));
        expect(axios.request).toHaveBeenCalledWith(
            expect.objectContaining({
                url: '/api/files/1/reaction',
                __atlasCsrfRetried: true,
            })
        );
        expect(result).toEqual({ data: { ok: true } });
    });

    it('does not retry infinitely', async () => {
        const axios = createAxiosMock();
        installAxiosCsrfRetryInterceptor(axios as any);

        const [, onRejected] = (axios.interceptors.response.use as any).mock.calls[0];

        const error = {
            response: { status: 419 },
            config: { __atlasCsrfRetried: true },
        };

        await expect(onRejected(error)).rejects.toBe(error);
    });
});

