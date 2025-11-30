<script setup lang="ts">
import { useRoute } from 'vue-router';

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
  <aside class="w-64 shrink-0 self-start">
    <nav class="sticky top-8 space-y-1">
      <router-link v-for="item in items" :key="item.name" :to="item.path"
        class="block px-4 py-2 rounded-lg transition-colors text-twilight-indigo-100 hover:bg-prussian-blue-600 hover:text-smart-blue-100 relative"
        :class="{
          'bg-smart-blue-600 text-white': isActive(item.name),
        }">
        <span class="absolute left-0 top-0 bottom-0 w-1 rounded-r transition-colors"
          :class="isActive(item.name) ? 'bg-smart-blue-400' : 'bg-transparent'"></span>
        <span class="font-medium">{{ item.label }}</span>
      </router-link>
    </nav>
  </aside>
</template>
