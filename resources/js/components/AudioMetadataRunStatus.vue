<script setup lang="ts">
defineProps<{
    message: string | null;
    error: string | null;
    progressLabel: string | null;
    progressPercent: number | null;
}>();
</script>

<template>
    <div v-if="message || error" class="border-x border-twilight-indigo-500 bg-prussian-blue-800 px-4 py-2 text-xs">
        <p v-if="message" class="text-smart-blue-100">{{ message }}</p>
        <p v-if="error" class="text-danger-100">{{ error }}</p>
        <div v-if="progressLabel" class="mt-2 flex items-center gap-3 text-blue-slate-300" data-test="audio-metadata-run-progress">
            <span class="shrink-0 tabular-nums">{{ progressLabel }}</span>
            <div
                class="h-1.5 min-w-0 flex-1 rounded-full bg-twilight-indigo-600"
                role="progressbar"
                aria-label="Metadata scan progress"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-valuenow="progressPercent ?? 0"
            >
                <div
                    class="h-1.5 rounded-full bg-smart-blue-400 transition-[width] duration-200"
                    :style="{ width: `${progressPercent ?? 0}%` }"
                />
            </div>
        </div>
    </div>
</template>
