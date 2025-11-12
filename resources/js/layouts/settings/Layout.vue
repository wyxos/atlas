<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { appearance } from '@/routes';
import { edit as editPassword } from '@/routes/password';
import { edit } from '@/routes/profile';
import { edit as editStorage } from '@/routes/storage';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const isAdmin = !!(page?.props as any)?.auth?.user?.is_admin;

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
    },
    {
        title: 'Password',
        href: editPassword(),
    },
    {
        title: 'Appearance',
        href: appearance(),
    },
    {
        title: 'Library',
        href: editStorage(),
    },
    // Admin-only
    ...(
        isAdmin
            ? [
                {
                    title: 'Plugins',
                    href: '/settings/plugins',
                } as NavItem,
                {
                    title: 'Test Moderation Rules',
                    href: '/moderation/test',
                } as NavItem,
            ]
            : []
    ),
];

const currentPath = typeof window !== undefined ? window.location.pathname : '';
</script>

<template>
    <div class="px-4 py-6 flex-1">
        <Heading title="Settings" description="Manage your profile and account settings" />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-y-1 space-x-0">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="typeof item.href === 'string' ? item.href : item.href?.url"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': currentPath === (typeof item.href === 'string' ? item.href : item.href?.url) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href" :data-test="item.title === 'Plugins' ? 'nav-settings-plugins' : undefined">
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
