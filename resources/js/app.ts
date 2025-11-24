import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import Oruga from '@oruga-ui/oruga-next';
import App from './App.vue';
import routes from './routes';
import './bootstrap';
import './icons';

// Only mount Vue app if the #app element exists AND we're on a Vue route
// This allows Blade pages to work without Vue, and Vue pages to work with Vue
const appElement = document.getElementById('app');
if (appElement) {
    // Only initialize Vue Router if we're on a page that should use Vue (like dashboard)
    // Check if we're on a route that should use Vue Router
    const currentPath = window.location.pathname;
    const vueRoutes = ['/dashboard', '/browse', '/audio', '/videos', '/photos', '/users', '/files', '/settings', '/profile', '/guidelines'];

    // Check if this is a Vue route by checking if the app element is empty
    // (Blade pages will have content, Vue pages will be empty)
    const isVueRoute = vueRoutes.some(route => currentPath.startsWith(route)) && appElement.children.length === 0;

    if (isVueRoute) {
        const router = createRouter({
            history: createWebHistory(),
            routes,
        });

        const app = createApp(App);
        app.use(router);
        app.use(Oruga);
        app.mount('#app');
    }
}

