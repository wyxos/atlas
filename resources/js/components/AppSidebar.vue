<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Headset, LayoutGrid, Video, Image, Users, Heart, ThumbsUp, ThumbsDown, Mic, Disc, ListMusic, HelpCircle, FileText } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Audio',
        href: '/audio',
        icon: Headset,
        isCollapsible: true,
        items: [
            {
                title: 'Favorites',
                href: '/audio/favorites',
                icon: Heart,
            },
            {
                title: 'Liked',
                href: '/audio/liked',
                icon: ThumbsUp,
            },
            {
                title: 'Disliked',
                href: '/audio/disliked',
                icon: ThumbsDown,
            },
            {
                title: 'Unrated',
                href: '/audio/unrated',
                icon: HelpCircle,
            },
            {
                title: 'Artists',
                href: '/audio/artists',
                icon: Mic,
            },
            {
                title: 'Albums',
                href: '/audio/albums',
                icon: Disc,
            },
            {
                title: 'Playlists',
                href: '/audio/playlists',
                icon: ListMusic,
            },
        ],
    },
    {
        title: 'Video',
        href: '/video',
        icon: Video
    },
    {
        title: 'Images',
        href: '/images',
        icon: Image
    },
    {
        title: 'Users',
        href: '/users',
        icon: Users
    },
    {
        title: 'Files',
        href: '/files',
        icon: FileText
    }
];

const footerNavItems: NavItem[] = [
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
