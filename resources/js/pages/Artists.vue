<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ChevronLeft, ChevronRight, Mic } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Artists',
        href: route('artists.index'),
    },
];

interface Artist {
    id: number;
    name: string;
    covers?: Array<{
        id: number;
        path: string;
    }>;
}

const props = defineProps<{
    artists: Artist[];
}>();

// Pagination state
const currentPage = ref(1);
const itemsPerPage = 12;

// Computed properties for pagination
const totalPages = computed(() => Math.ceil(props.artists.length / itemsPerPage));
const paginatedArtists = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    return props.artists.slice(start, end);
});

// Pagination methods
function goToPage(page: number): void {
    if (page >= 1 && page <= totalPages.value) {
        currentPage.value = page;
    }
}

function nextPage(): void {
    if (currentPage.value < totalPages.value) {
        currentPage.value++;
    }
}

function prevPage(): void {
    if (currentPage.value > 1) {
        currentPage.value--;
    }
}

// Get artist cover image
function getArtistCover(artist: Artist): string {
    if (artist.covers && artist.covers.length > 0) {
        return `/storage/${artist.covers[0].path}`;
    }
    return '';
}
</script>

<template>
    <Head title="Artists" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="mb-6">
                <h1 class="text-3xl font-bold">Artists</h1>
                <p class="text-gray-600 mt-2">Browse your music collection by artist</p>
            </div>

            <!-- Artists Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-4 mb-8">
                <Card
                    v-for="artist in paginatedArtists"
                    :key="artist.id"
                    class="hover:shadow-lg transition-shadow cursor-pointer"
                >
                    <CardContent class="p-4">
                        <div class="aspect-square mb-3 bg-gray-100 rounded-lg flex items-center justify-center overflow-hidden">
                            <img
                                v-if="getArtistCover(artist)"
                                :src="getArtistCover(artist)"
                                :alt="artist.name"
                                class="w-full h-full object-cover"
                            />
                            <Mic v-else class="w-12 h-12 text-gray-400" />
                        </div>
                        <h3 class="font-semibold text-sm truncate" :title="artist.name">
                            {{ artist.name }}
                        </h3>
                    </CardContent>
                </Card>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" class="flex items-center justify-center space-x-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="currentPage === 1"
                    @click="prevPage"
                >
                    <ChevronLeft class="w-4 h-4" />
                    Previous
                </Button>

                <div class="flex space-x-1">
                    <Button
                        v-for="page in totalPages"
                        :key="page"
                        :variant="page === currentPage ? 'default' : 'outline'"
                        size="sm"
                        @click="goToPage(page)"
                        class="min-w-[40px]"
                    >
                        {{ page }}
                    </Button>
                </div>

                <Button
                    variant="outline"
                    size="sm"
                    :disabled="currentPage === totalPages"
                    @click="nextPage"
                >
                    Next
                    <ChevronRight class="w-4 h-4" />
                </Button>
            </div>

            <!-- Empty state -->
            <div v-if="artists.length === 0" class="text-center py-12">
                <Mic class="w-16 h-16 text-gray-400 mx-auto mb-4" />
                <h3 class="text-lg font-semibold text-gray-600 mb-2">No Artists Found</h3>
                <p class="text-gray-500">No artists are available in your music collection.</p>
            </div>
        </div>
    </AppLayout>
</template>
