<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginationData {
    links: PaginationLink[];
    meta?: any;
}

defineProps<{
    data: PaginationData;
}>();
</script>

<template>
    <div v-if="data.links && data.links.length > 3" class="mt-4 flex justify-center">
        <nav class="flex space-x-2">
            <template v-for="link in data.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded-md px-3 py-2 text-sm min-w-12 text-center border-2"
                    :class="{
                        'bg-blue-500 text-white': link.active,
                        'text-tertiary hover:bg-gray-300': !link.active,
                    }"
                >
                    <ChevronLeft v-if="link.label.includes('Previous')" class="" :size="20" />
                    <ChevronRight v-else-if="link.label.includes('Next')" class="" :size="20" />
                    <span v-else>{{ link.label }}</span>
                </Link>
                <span v-else class="px-3 py-2 text-sm min-w-12 text-center text-gray-400">
                    <ChevronLeft v-if="link.label.includes('Previous')" class="" :size="20" />
                    <ChevronRight v-else-if="link.label.includes('Next')" class="" :size="20" />
                    <span v-else>{{ link.label }}</span>
                </span>
            </template>
        </nav>
    </div>
</template>
