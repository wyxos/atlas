import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
    {
        path: '/dashboard',
        name: 'dashboard',
        component: () => import('./pages/Dashboard.vue'),
    },
    {
        path: '/users',
        name: 'users',
        component: () => import('./pages/Users.vue'),
    },
    // Don't redirect / to /dashboard - let Laravel handle it
    // The home page is a Blade template, not a Vue route
    // Catch-all route for non-Vue pages (Blade templates like /login, /home, etc.)
    // This prevents Vue Router warnings when Vue mounts on Blade pages
    {
        path: '/:pathMatch(.*)*',
        name: 'catch-all',
        component: {
            render: () => null,
        },
    },
];

export default routes;

