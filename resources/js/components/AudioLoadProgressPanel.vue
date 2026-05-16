<script setup lang="ts">
defineProps<{
    loadedPages: number;
    totalPages: number;
    progressPercent: number;
    loadedIds: number;
    totalAudioFiles: number;
    isLoading: boolean;
}>();
</script>

<template>
    <div
        class="mb-4 overflow-hidden rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700 p-4"
        data-test="audio-progress-panel"
    >
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
            IDs loaded: {{ loadedIds }} / {{ totalAudioFiles }}
            <span v-if="isLoading" class="ml-2">Loading...</span>
        </div>
    </div>
</template>
