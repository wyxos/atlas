<script lang="ts" setup>
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Masonry } from '@wyxos/vibe';
import { ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Images',
        href: route('images.index'),
    },
];

const items = ref([]);

const masonry = ref(null);

const getPage = async (page: number) => {};
</script>

<template>
    <Head title="Images" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6">
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
            ></Masonry>
        </div>
    </AppLayout>
</template>
