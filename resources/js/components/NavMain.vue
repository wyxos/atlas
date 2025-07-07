<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarMenuSub, SidebarMenuSubButton, SidebarMenuSubItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ChevronRight } from 'lucide-vue-next';

defineProps<{
    items: NavItem[];
}>();

const page = usePage();
const expandedItems = ref<Set<string>>(new Set());

function toggleExpanded(itemTitle: string): void {
    if (expandedItems.value.has(itemTitle)) {
        expandedItems.value.delete(itemTitle);
    } else {
        expandedItems.value.add(itemTitle);
    }
}

function isExpanded(itemTitle: string): boolean {
    return expandedItems.value.has(itemTitle);
}

function isItemActive(item: NavItem): boolean {
    if (item.href === page.url) return true;
    if (item.items) {
        return item.items.some(subItem => subItem.href === page.url);
    }
    return false;
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Platform</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <!-- Item with sub-items (collapsible) -->
                <template v-if="item.items && item.items.length > 0">
                    <SidebarMenuButton
                        :is-active="isItemActive(item)"
                        :tooltip="item.title"
                        as-child
                    >
                        <Link :href="item.href" class="flex items-center w-full">
                            <component :is="item.icon" />
                            <span class="flex-1">{{ item.title }}</span>
                            <ChevronRight
                                class="ml-auto transition-transform duration-200 cursor-pointer"
                                :class="{ 'rotate-90': isExpanded(item.title) }"
                                @click.prevent.stop="toggleExpanded(item.title)"
                            />
                        </Link>
                    </SidebarMenuButton>
                    <SidebarMenuSub v-if="isExpanded(item.title)">
                        <SidebarMenuSubItem v-for="subItem in item.items" :key="subItem.title">
                            <SidebarMenuSubButton as-child :is-active="subItem.href === page.url">
                                <Link :href="subItem.href">
                                    <component v-if="subItem.icon" :is="subItem.icon" />
                                    <span>{{ subItem.title }}</span>
                                </Link>
                            </SidebarMenuSubButton>
                        </SidebarMenuSubItem>
                    </SidebarMenuSub>
                </template>

                <!-- Regular item without sub-items -->
                <template v-else>
                    <SidebarMenuButton as-child :is-active="item.href === page.url" :tooltip="item.title">
                        <Link :href="item.href">
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </template>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
