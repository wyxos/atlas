import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import routes from './routes';
import './bootstrap';
import './icons';

// Only mount Vue app if the #app element exists (for Blade pages that use Vue)
// This allows Blade pages to work without Vue, and Vue pages to work with Vue
const appElement = document.getElementById('app');
if (appElement) {
    const router = createRouter({
        history: createWebHistory(),
        routes,
    });

    const app = createApp(App);
    app.use(router);
    app.mount('#app');
}

