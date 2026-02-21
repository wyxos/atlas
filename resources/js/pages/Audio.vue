<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import PageLayout from '../components/PageLayout.vue';
import VirtualList from '../components/VirtualList.vue';

type AudioIdsResponse = {
    ids: number[];
    pagination: {
        page: number;
        per_page: number;
        total: number;
        total_pages: number;
    };
};

const PER_PAGE = 500;

const audioIds = ref<number[]>([]);
const loadedPages = ref(0);
const totalPages = ref(0);
const totalAudioFiles = ref(0);
const isLoading = ref(false);
const error = ref<string | null>(null);

const progressPercent = computed(() => {
    if (totalPages.value === 0) {
        return 100;
    }

    return Math.round((loadedPages.value / totalPages.value) * 100);
});

let isDisposed = false;

async function fetchPage(page: number): Promise<AudioIdsResponse> {
    const { data } = await window.axios.get<AudioIdsResponse>('/api/audio/ids', {
        params: {
            page,
            per_page: PER_PAGE,
        },
    });

    return data;
}

async function loadAllAudioIds(): Promise<void> {
    isLoading.value = true;
    error.value = null;
    audioIds.value = [];
    loadedPages.value = 0;
    totalPages.value = 0;
    totalAudioFiles.value = 0;

    try {
        const firstPage = await fetchPage(1);
        if (isDisposed) {
            return;
        }

        totalPages.value = firstPage.pagination.total_pages;
        totalAudioFiles.value = firstPage.pagination.total;
        audioIds.value.push(...firstPage.ids);
        loadedPages.value = 1;

        for (let page = 2; page <= totalPages.value; page += 1) {
            const nextPage = await fetchPage(page);
            if (isDisposed) {
                return;
            }

            audioIds.value.push(...nextPage.ids);
            loadedPages.value = page;
        }
    } catch (loadError) {
        console.error('Failed to load audio IDs:', loadError);
        error.value = 'Failed to load audio IDs.';
    } finally {
        if (!isDisposed) {
            isLoading.value = false;
        }
    }
}

onMounted(() => {
    void loadAllAudioIds();
});

onUnmounted(() => {
    isDisposed = true;
});
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <div class="mb-6">
                <h4 class="text-2xl font-semibold text-regal-navy-100 mb-2">Audio</h4>
                <p class="text-blue-slate-300">All audio file IDs</p>
            </div>

            <div class="mb-4 rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700 p-4">
                <div class="mb-2 flex items-center justify-between text-sm text-twilight-indigo-100">
                    <span>Pages: {{ loadedPages }} / {{ totalPages }}</span>
                    <span>{{ progressPercent }}%</span>
                </div>
                <div class="h-2 w-full rounded-full bg-twilight-indigo-600">
                    <div
                        class="h-2 rounded-full bg-smart-blue-400 transition-[width] duration-200"
                        :style="{ width: `${progressPercent}%` }"
                    />
                </div>
                <div class="mt-2 text-xs text-blue-slate-300">
                    IDs loaded: {{ audioIds.length }} / {{ totalAudioFiles }}
                    <span v-if="isLoading" class="ml-2">Loading...</span>
                </div>
            </div>

            <div v-if="error" class="rounded-lg border border-danger-500 bg-prussian-blue-700 p-4 text-danger-200">
                {{ error }}
            </div>
            <div v-else class="rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700">
                <div v-if="!isLoading && audioIds.length === 0" class="p-4 text-twilight-indigo-100">
                    No audio files found.
                </div>
                <VirtualList
                    v-else
                    :items="audioIds"
                    :item-height="48"
                    container-class="max-h-[70vh] overflow-y-auto"
                >
                    <template #default="{ items }">
                        <ul class="divide-y divide-twilight-indigo-500">
                            <li
                                v-for="audioId in items"
                                :key="audioId"
                                class="h-12 px-4 font-mono text-sm text-twilight-indigo-100 flex items-center"
                            >
                                {{ audioId }}
                            </li>
                        </ul>
                    </template>
                </VirtualList>
            </div>
        </div>
    </PageLayout>
</template>
