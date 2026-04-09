import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import Oruga from '@oruga-ui/oruga-next';
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
        Echo?: Echo<'reverb'>;
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

    app.mount('#app');
}
