<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Headset, LayoutGrid, Video, Image, Users, Heart, ThumbsUp, ThumbsDown, Mic, Disc, ListMusic, HelpCircle, FileText, Smile, Podcast } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

interface Playlist {
    id: number;
    name: string;
}

const page = usePage<{
    playlists: {
        data: Playlist[];
        links: any[];
    };
}>();

const mainNavItems = computed((): NavItem[] => {
    const playlistItems: NavItem[] = (page.props.playlists?.data || []).map((playlist) => ({
        title: playlist.name,
        href: `/playlists/${playlist.id}`,
        icon: ListMusic,
    }));

    return [
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
                    title: 'Funny',
                    href: '/audio/funny',
                    icon: Smile,
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
                    href: '/artists',
                    icon: Mic,
                },
                {
                    title: 'Albums',
                    href: '/albums',
                    icon: Disc,
                },
                {
                    title: 'Podcasts',
                    href: '/audio/podcasts',
                    icon: Podcast,
                },
            ],
        },
        {
            title: 'Video',
            href: '/video',
            icon: Video,
            isCollapsible: true,
            items: [
                {
                    title: 'Movies',
                    href: '/video/movies',
                    icon: Video,
                },
                {
                    title: 'Series',
                    href: '/video/series',
                    icon: Video,
                },
                {
                    title: 'Various',
                    href: '/video/various',
                    icon: Video,
                },
            ],
        },
        {
            title: 'Images',
            href: '/images',
            icon: Image,
            isCollapsible: true,
            items: [
                {
                    title: 'Books',
                    href: '/images/books',
                    icon: Image,
                },
                {
                    title: 'Sets',
                    href: '/images/sets',
                    icon: Image,
                },
                {
                    title: 'Various',
                    href: '/images/various',
                    icon: Image,
                },
            ],
        },
        {
            title: 'Playlists',
            href: '/playlists',
            icon: ListMusic,
            isCollapsible: true,
            items: playlistItems,
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
});

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
