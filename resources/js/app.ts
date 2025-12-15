import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import Oruga from '@oruga-ui/oruga-next';
import { library } from '@fortawesome/fontawesome-svg-core';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { fas } from '@fortawesome/free-solid-svg-icons';
import Toast, { POSITION } from 'vue-toastification';
import 'vue-toastification/dist/index.css';
import App from './App.vue';
import routes from './routes';
import './bootstrap';
import './icons';

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
        position: POSITION.BOTTOM_RIGHT,
        timeout: 5000,
        pauseOnHover: false, // We'll handle this manually to integrate with useReactionQueue
        pauseOnFocusLoss: true,
        hideProgressBar: true, // Hide default progress bar, we use our own
        closeOnClick: false,
        closeButton: false, // Hide default close button, we use our own
        draggable: false,
        icon: false, // Hide default icon, we use our own
        toastClassName: 'reaction-toast',
        containerClassName: 'reaction-toast-container',
        onMounted: (containerApp, containerComponent) => {
            // Access the toast container element
            const container = containerComponent.$el;
            if (container) {
                // Use event delegation to handle hover on all toasts
                container.addEventListener('mouseenter', (e: Event) => {
                    const target = e.target as HTMLElement;
                    // Check if we're hovering over a toast element
                    if (target.closest('.reaction-toast') || target.closest('.Vue-Toastification__toast')) {
                        const win = window as any;
                        if (win.__reactionQueuePauseAll) {
                            win.__reactionQueuePauseAll();
                        }
                    }
                }, true);
                container.addEventListener('mouseleave', (e: Event) => {
                    const target = e.target as HTMLElement;
                    // Check if we're leaving a toast element
                    if (target.closest('.reaction-toast') || target.closest('.Vue-Toastification__toast')) {
                        const win = window as any;
                        if (win.__reactionQueueResumeAll) {
                            win.__reactionQueueResumeAll();
                        }
                    }
                }, true);
            }
        },
    });

    app.mount('#app');
}

