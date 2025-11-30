<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();

const items = [
  { name: 'guidelines', path: '/guidelines', label: 'Overview' },
  { name: 'guidelines-buttons', path: '/guidelines#buttons', label: 'Buttons' },
  { name: 'guidelines-colors', path: '/guidelines/colors', label: 'Color Palette' },
  { name: 'guidelines-headings', path: '/guidelines/headings', label: 'Headings' },
  { name: 'guidelines-badges', path: '/guidelines/badges', label: 'Badges' },
  { name: 'guidelines-pills', path: '/guidelines/pills', label: 'Pills' },
  { name: 'guidelines-tables', path: '/guidelines/tables', label: 'Tables' },
  { name: 'guidelines-pagination', path: '/guidelines/pagination', label: 'Pagination' },
];

const isActive = computed(() => (itemName: string, path: string) => {
  // Direct route name match
  if (route.name === itemName) return true;
  // Buttons section: hash on /guidelines
  if (itemName === 'guidelines-buttons' && route.path === '/guidelines' && route.hash === '#buttons') return true;
  // Overview: /guidelines without hash
  if (itemName === 'guidelines' && route.path === '/guidelines' && !route.hash) return true;
  return false;
});
</script>

<template>
  <aside class="w-64 shrink-0">
    <nav class="sticky top-8 space-y-1">
      <router-link
        v-for="item in items"
        :key="item.name"
        :to="item.path"
        class="block px-4 py-2 rounded-lg transition-colors text-twilight-indigo-100 hover:bg-prussian-blue-600 hover:text-smart-blue-100"
        :class="{ 'bg-prussian-blue-600 text-smart-blue-100': isActive(item.name, item.path) }"
      >
        <span class="font-medium">{{ item.label }}</span>
      </router-link>
    </nav>
  </aside>
</template>


