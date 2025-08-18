<script lang="ts" setup>
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
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

// Paginated data loader modeled after Browse.vue
const getPage = async (pageParam: number | string) => {
    try {
        const limit = 40; // default page size for images
        const queryParams = {
            page: pageParam,
            limit,
        };

        return new Promise((resolve, reject) => {
            router.get(
                route('images.data', queryParams),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['items', 'filters'],
                    onSuccess: (response) => {
                        const newItems = (response.props.items || []) as any[];
                        const filters = response.props.filters as { page: number | null; nextPage: number | null };
                        console.log('Fetched images page data:', newItems, filters);
                        resolve({
                            items: newItems,
                            nextPage: filters?.nextPage ?? null,
                        });
                    },
                    onError: (errors) => {
                        console.error('Error fetching images page data:', errors);
                        reject(new Error('Failed to fetch images page data'));
                    },
                },
            );
        });
    } catch (error) {
        console.error('Error in getPage (Images):', error);
        throw new Error('Failed to load images page data.');
    }
};
</script>

<template>
    <Head title="Images" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="h-screen p-6">
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
                    <img
                        :src="item.src"
                        alt="Image"
                        class="h-auto w-full rounded-lg object-cover shadow-md transition-transform duration-300 hover:scale-105"
                    />
                </template>
            </Masonry>
        </div>
    </AppLayout>
</template>
