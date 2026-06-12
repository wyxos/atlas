<script setup lang="ts">
import { Loader2, RefreshCw } from 'lucide-vue-next';
import { computed } from 'vue';
import type { File } from '@/types/file';

const props = withDefaults(defineProps<{
    fileData: File | null;
    isRefreshing?: boolean;
    error?: string | null;
}>(), {
    isRefreshing: false,
    error: null,
});

const emit = defineEmits<{
    refresh: [fileId: number];
}>();

const canRefresh = computed(() => {
    return props.fileData?.capabilities?.restore_detail_metadata === true;
});
</script>

<template>
    <div
        v-if="canRefresh && fileData"
        class="shrink-0 border-b border-twilight-indigo-500 bg-prussian-blue-900/35 px-4 py-3 text-sm"
        data-test="file-source-metadata-refresh-panel"
    >
        <div class="flex min-w-0 items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="truncate text-xs font-semibold uppercase tracking-wide text-twilight-indigo-300">
                    {{ fileData.source }} metadata
                </div>
            </div>
            <button
                type="button"
                class="inline-flex shrink-0 items-center gap-2 rounded border border-smart-blue-400/60 bg-smart-blue-500/15 px-3 py-2 text-sm font-semibold text-smart-blue-100 transition hover:border-smart-blue-300 hover:bg-smart-blue-500/25 focus:outline-none focus:ring-2 focus:ring-smart-blue-300 disabled:cursor-not-allowed disabled:opacity-60"
                data-test="file-source-metadata-refresh"
                :disabled="isRefreshing"
                @click="emit('refresh', fileData.id)"
            >
                <Loader2 v-if="isRefreshing" :size="16" class="animate-spin" />
                <RefreshCw v-else :size="16" />
                <span>{{ isRefreshing ? 'Fetching metadata...' : 'Fetch metadata' }}</span>
            </button>
        </div>
        <div v-if="error" class="mt-2 text-xs text-red-200">
            {{ error }}
        </div>
    </div>
</template>
