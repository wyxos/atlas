import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        redirect: '/dashboard',
    },
    {
        path: '/dashboard',
        name: 'dashboard',
        component: () => import('./pages/Dashboard.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/browse',
        name: 'browse',
        component: () => import('./pages/Browse.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/audio',
        name: 'audio',
        component: () => import('./pages/Audio.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/videos',
        name: 'videos',
        component: () => import('./pages/Videos.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/photos',
        name: 'photos',
        component: () => import('./pages/Photos.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/users',
        name: 'users',
        component: () => import('./pages/Users.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/files',
        name: 'files',
        component: () => import('./pages/Files.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/files/:id',
        name: 'file-detail',
        component: () => import('./pages/FileDetail.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/settings',
        name: 'settings',
        component: () => import('./pages/Settings.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/profile',
        name: 'profile',
        component: () => import('./pages/Profile.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/guidelines',
        component: () => import('./components/GuidelinesPageLayout.vue'),
        meta: { layout: 'DashboardLayout' },
        children: [
            {
                path: '',
                name: 'guidelines',
                component: () => import('./pages/Guidelines.vue'),
            },
            {
                path: 'buttons',
                name: 'guidelines-buttons',
                component: () => import('./pages/Buttons.vue'),
            },
            {
                path: 'colors',
                name: 'guidelines-colors',
                component: () => import('./pages/ColorPalette.vue'),
            },
            {
                path: 'headings',
                name: 'guidelines-headings',
                component: () => import('./pages/Headings.vue'),
            },
            {
                path: 'badges',
                name: 'guidelines-badges',
                component: () => import('./pages/Badges.vue'),
            },
            {
                path: 'pills',
                name: 'guidelines-pills',
                component: () => import('./pages/Pills.vue'),
            },
            {
                path: 'tables',
                name: 'guidelines-tables',
                component: () => import('./pages/Tables.vue'),
            },
            {
                path: 'pagination',
                name: 'guidelines-pagination',
                component: () => import('./pages/Pagination.vue'),
            },
        ],
    },
];

export default routes;
