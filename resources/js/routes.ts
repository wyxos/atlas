import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
    {
        path: '/dashboard',
        name: 'dashboard',
        component: () => import('./pages/Dashboard.vue'),
    },
    {
        path: '/browse',
        name: 'browse',
        component: () => import('./pages/Browse.vue'),
    },
    {
        path: '/audio',
        name: 'audio',
        component: () => import('./pages/Audio.vue'),
    },
    {
        path: '/videos',
        name: 'videos',
        component: () => import('./pages/Videos.vue'),
    },
    {
        path: '/photos',
        name: 'photos',
        component: () => import('./pages/Photos.vue'),
    },
    {
        path: '/users',
        name: 'users',
        component: () => import('./pages/Users.vue'),
    },
    {
        path: '/files',
        name: 'files',
        component: () => import('./pages/Files.vue'),
    },
    {
        path: '/settings',
        name: 'settings',
        component: () => import('./pages/Settings.vue'),
    },
    {
        path: '/profile',
        name: 'profile',
        component: () => import('./pages/Profile.vue'),
    },
];

export default routes;

