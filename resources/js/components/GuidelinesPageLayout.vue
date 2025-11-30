<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronRight } from 'lucide-vue-next';
import PageLayout from './PageLayout.vue';
import GuidelinesNav from './GuidelinesNav.vue';
import { SidebarProvider, SidebarInset, SidebarTrigger } from '@/components/ui/sidebar';

const route = useRoute();

const breadcrumbs = computed(() => {
    const crumbs = [{ name: 'Guidelines', path: '/guidelines' }];

    if (route.name === 'guidelines') {
        return crumbs;
    }

    const routeNames: Record<string, string> = {
        'guidelines-buttons': 'Buttons',
        'guidelines-colors': 'Color Palette',
        'guidelines-headings': 'Headings',
        'guidelines-badges': 'Badges',
        'guidelines-pills': 'Pills',
        'guidelines-tables': 'Tables',
        'guidelines-pagination': 'Pagination',
    };

    const currentName = routeNames[route.name as string];
    if (currentName) {
        crumbs.push({ name: currentName, path: route.path });
    }

    return crumbs;
});
</script>

<template>
    <PageLayout>
        <SidebarProvider>
            <GuidelinesNav />
            <SidebarInset class="bg-prussian-blue-600">
                <header class="flex h-16 shrink-0 items-center gap-2 border-b border-twilight-indigo-500 bg-prussian-blue-600 px-4">
                    <div class="flex items-center gap-2">
                        <SidebarTrigger class="-ml-1" />
                        <nav class="flex items-center gap-2 text-sm text-twilight-indigo-300">
                            <router-link v-for="(crumb, index) in breadcrumbs" :key="crumb.path" :to="crumb.path"
                                class="flex items-center gap-2 hover:text-smart-blue-100 transition-colors">
                                <span>{{ crumb.name }}</span>
                                <ChevronRight v-if="index < breadcrumbs.length - 1" class="h-4 w-4" />
                            </router-link>
                        </nav>
                    </div>
                </header>
                <div class="flex flex-1 flex-col p-4 md:p-8 bg-prussian-blue-600">
                    <router-view :key="route.fullPath" />
                </div>
            </SidebarInset>
        </SidebarProvider>
    </PageLayout>
</template>
