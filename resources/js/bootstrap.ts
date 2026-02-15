import axios from 'axios';
import { installAxiosCsrfRetryInterceptor } from '@/utils/axiosCsrfRetry';

declare global {
    interface Window {
        axios: typeof axios;
    }
}

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Prefer Laravel's XSRF cookie over pinning a CSRF token from the initial HTML.
// The token can rotate when the session is regenerated; the cookie will stay current.
window.axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
window.axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

// Auto-recover from 419 (CSRF mismatch) without forcing a full reload.
installAxiosCsrfRetryInterceptor(window.axios);

