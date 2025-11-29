import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import Oruga from '@oruga-ui/oruga-next';
import { library } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { fas } from '@fortawesome/free-solid-svg-icons';
import '@oruga-ui/theme-oruga/style.css';
import App from './App.vue';
import routes from './routes';
import './bootstrap';
import './icons';

import * as Sentry from '@sentry/browser';

Sentry.init({
    integrations: [Sentry.browserTracingIntegration()],
    // ...other Sentry options
});

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

