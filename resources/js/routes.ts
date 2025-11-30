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
        name: 'guidelines',
        component: () => import('./pages/Guidelines.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/guidelines/colors',
        name: 'guidelines-colors',
        component: () => import('./pages/ColorPalette.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/guidelines/headings',
        name: 'guidelines-headings',
        component: () => import('./pages/Headings.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/guidelines/badges',
        name: 'guidelines-badges',
        component: () => import('./pages/Badges.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/guidelines/pills',
        name: 'guidelines-pills',
        component: () => import('./pages/Pills.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/guidelines/tables',
        name: 'guidelines-tables',
        component: () => import('./pages/Tables.vue'),
        meta: { layout: 'DashboardLayout' },
    },
    {
        path: '/guidelines/pagination',
        name: 'guidelines-pagination',
        component: () => import('./pages/Pagination.vue'),
        meta: { layout: 'DashboardLayout' },
    },
];

export default routes;
