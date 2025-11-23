import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import routes from './routes';
import './bootstrap';

// Only mount Vue app if the #app element exists (for Blade pages that use Vue)
// This allows Blade pages to work without Vue, and Vue pages to work with Vue
if (document.getElementById('app')) {
    const router = createRouter({
        history: createWebHistory(),
        routes,
    });

    const app = createApp(App);
    app.use(router);
    app.mount('#app');
}
