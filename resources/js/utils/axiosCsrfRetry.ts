import type { AxiosInstance, AxiosRequestConfig } from 'axios';

type AxiosLike = Pick<AxiosInstance, 'get' | 'request' | 'interceptors'>;

type CsrfRetryOptions = {
    refreshUrl?: string;
};

let refreshPromise: Promise<void> | null = null;

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

async function ensureFreshCsrfCookie(axios: AxiosLike, refreshUrl: string): Promise<void> {
    if (!refreshPromise) {
        refreshPromise = axios
            .get(refreshUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            })
            .then(() => undefined)
            .finally(() => {
                refreshPromise = null;
            });
    }

    await refreshPromise;
}

/**
 * Retry once on 419 by refreshing the XSRF cookie, without forcing a full page reload.
 */
export function installAxiosCsrfRetryInterceptor(axios: AxiosLike, options?: CsrfRetryOptions): void {
    const refreshUrl = options?.refreshUrl ?? '/api/csrf';
    const retryKey = '__atlasCsrfRetried' as const;

    axios.interceptors.response.use(
        (response) => response,
        async (error) => {
            const err = isRecord(error) ? error : null;
            const response = err && isRecord(err.response) ? err.response : null;
            const status = response && typeof response.status === 'number' ? response.status : null;
            const config = err && isRecord(err.config) ? (err.config as AxiosRequestConfig) : null;

            if (status !== 419 || !config) {
                return Promise.reject(error);
            }

            const configRecord = config as unknown as Record<string, unknown>;

            if (configRecord[retryKey]) {
                return Promise.reject(error);
            }

            configRecord[retryKey] = true;

            try {
                await ensureFreshCsrfCookie(axios, refreshUrl);
            } catch {
                return Promise.reject(error);
            }

            return axios.request(config);
        }
    );
}
