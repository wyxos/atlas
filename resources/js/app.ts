import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import Oruga from '@oruga-ui/oruga-next';
import Toast, { POSITION } from 'vue-toastification';
import 'vue-toastification/dist/index.css';
import { library } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { fas } from '@fortawesome/free-solid-svg-icons';
import App from './App.vue';
import routes from './routes';
import './bootstrap';
import './icons';
import { configureEcho, echo } from '@laravel/echo-vue';
import type Echo from 'laravel-echo';

declare global {
    interface Window {
        Echo?: Echo;
    }
}

configureEcho({
    broadcaster: 'reverb',
});

try {
    window.Echo = echo();
} catch {
    window.Echo = undefined;
}

// Add all solid icons to the library
library.add(fas);

// Only mount Vue app if the #app element exists AND is empty (Vue SPA route)
// Blade pages have content after the #app element, Vue SPA pages have an empty #app element
const appElement = document.getElementById('app');
if (appElement && appElement.children.length === 0 && !appElement.nextElementSibling) {
    const router = createRouter({
        history: createWebHistory(),
        routes,
    });

    const app = createApp(App);
    app.use(router);

    // Register FontAwesomeIcon component globally
    app.component('font-awesome-icon', FontAwesomeIcon);

    // Use Oruga with Font Awesome as the default icon pack
    app.use(Oruga, {
        iconPack: 'fas',
        iconComponent: 'font-awesome-icon',
        pagination: {
            iconPrev: 'chevron-left',
            iconNext: 'chevron-right',
        },
    });

    // Register Vue Toastification
    app.use(Toast, {
        position: POSITION.BOTTOM_RIGHT, // Position toasts at bottom right
        maxToasts: Infinity, // No limit on toasts
        newestOnTop: true,
        timeout: false, // Default to no auto-dismiss (we handle it in queue)
        closeOnClick: false, // Don't close on click (we handle it manually)
        pauseOnFocusLoss: false, // Don't pause on focus loss
        pauseOnHover: false, // Don't pause on hover (we handle freeze in queue)
        hideProgressBar: true, // Hide default progress bar (we use our own)
        icon: false, // Disable default icons (we use our own)
        closeButton: false, // Hide default close button (we use our own)
        toastClassName: 'custom-toast', // Default class for all toasts
    });

    app.mount('#app');
}

