import axios from 'axios';

declare global {
    interface Window {
        axios: typeof axios;
    }
}

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Set up CSRF token for axios
// Wait for DOM to be ready to ensure meta tag is available
function setupCsrfToken(): void {
    const token = document.querySelector('meta[name="csrf-token"]');
    if (token) {
        const tokenValue = token.getAttribute('content');
        if (tokenValue) {
            window.axios.defaults.headers.common['X-CSRF-TOKEN'] = tokenValue;
        }
    }
}

// Try immediately
setupCsrfToken();

// Also try after DOM is ready (in case meta tag loads later)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupCsrfToken);
} else {
    setupCsrfToken();
}

