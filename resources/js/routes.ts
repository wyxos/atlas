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
    {
        path: '/',
        redirect: '/dashboard',
    },
];

export default routes;

