<script lang="ts" setup>
import { createMasonryPageLoader } from '@/composables/useMasonryData';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Masonry } from '@wyxos/vibe';
import { ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Images', href: route('images.index') },
    { title: 'Blacklisted', href: route('images.blacklisted') },
];

const items = ref([]);
const masonry = ref(null);
const getPage = createMasonryPageLoader({ routeName: 'images.blacklisted.data', defaultLimit: 40 });
</script>

<template>
    <Head title="Blacklisted Images" />
  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-screen flex-col overflow-hidden">
      <!-- Masonry Container -->
      <div class="relative min-h-0 flex-1">
        <Masonry
          ref="masonry"
          v-model:items="items"
          :get-next-page="getPage"
          :layout="{
            sizes: { base: 1, sm: 2, md: 3, lg: 3, xl: 5, '2xl': 8 },
            footer: 32,
          }"
          :load-at-page="1"
          :max-items="150"
          class="h-full"
        >
          <template #item="{ item }">
            <img :src="item.image_url || item.src" alt="Image" class="h-full w-full cursor-pointer object-cover" />
          </template>
        </Masonry>
      </div>
    </div>
  </AppLayout>
</template>
