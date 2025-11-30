<script setup lang="ts">
import { useRoute } from 'vue-router';
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from '@/components/ui/sidebar';

const route = useRoute();

const items = [
  { name: 'guidelines', path: '/guidelines', label: 'Overview' },
  { name: 'guidelines-buttons', path: '/guidelines/buttons', label: 'Buttons' },
  { name: 'guidelines-colors', path: '/guidelines/colors', label: 'Color Palette' },
  { name: 'guidelines-headings', path: '/guidelines/headings', label: 'Headings' },
  { name: 'guidelines-badges', path: '/guidelines/badges', label: 'Badges' },
  { name: 'guidelines-pills', path: '/guidelines/pills', label: 'Pills' },
  { name: 'guidelines-tables', path: '/guidelines/tables', label: 'Tables' },
  { name: 'guidelines-pagination', path: '/guidelines/pagination', label: 'Pagination' },
];

function isActive(itemName: string): boolean {
  return route.name === itemName;
}
</script>

<template>
  <Sidebar collapsible="none" class="bg-prussian-blue-600 border-r border-twilight-indigo-500">
    <SidebarContent>
      <SidebarGroup>
        <SidebarGroupContent>
          <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.name">
              <SidebarMenuButton as-child :is-active="isActive(item.name)"
                class="text-twilight-indigo-100 hover:bg-prussian-blue-500 data-[active=true]:bg-smart-blue-600 data-[active=true]:text-white">
                <router-link :to="item.path" class="flex items-center">
                  <span>{{ item.label }}</span>
                </router-link>
              </SidebarMenuButton>
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarGroupContent>
      </SidebarGroup>
    </SidebarContent>
  </Sidebar>
</template>
