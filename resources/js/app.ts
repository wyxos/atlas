import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import Oruga from '@oruga-ui/oruga-next';
import App from './App.vue';
import routes from './routes';
import './bootstrap';
import './icons';

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
    app.use(Oruga);
    app.mount('#app');
}

