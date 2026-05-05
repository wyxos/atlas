<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Image as ImageIcon, Loader2, Video } from 'lucide-vue-next';
import type { VibeFullscreenPreviewItem } from '@wyxos/vibe';

type PreviewLoadState = 'error' | 'loading' | 'ready';

const props = defineProps<{
    previews: VibeFullscreenPreviewItem[];
    totalItems: number;
}>();

const emit = defineEmits<{
    select: [index: number];
}>();

const previewLoadStates = ref<Record<string, PreviewLoadState>>({});
const visiblePreviews = computed(() => props.previews.slice(0, 2));

watch(
    visiblePreviews,
    (previews) => {
        const nextStates: Record<string, PreviewLoadState> = {};

        for (const preview of previews) {
            const key = getPreviewKey(preview);
            nextStates[key] = isLoadablePreview(preview)
                ? previewLoadStates.value[key] ?? 'loading'
                : 'ready';
        }

        previewLoadStates.value = nextStates;
    },
    { immediate: true },
);

function getPreviewKey(preview: VibeFullscreenPreviewItem): string {
    return `${preview.item.id}:${preview.index}:${preview.asset.url ?? 'fallback'}`;
}

function getPreviewFitClass(preview: VibeFullscreenPreviewItem): string {
    return shouldCropPreview(preview) ? 'object-cover' : 'object-contain';
}

function getPreviewLabel(preview: VibeFullscreenPreviewItem): string {
    return `Open item ${preview.index + 1} of ${props.totalItems}: ${preview.asset.label}`;
}

function shouldCropPreview(preview: VibeFullscreenPreviewItem): boolean {
    if (preview.asset.width <= 0 || preview.asset.height <= 0) {
        return false;
    }

    const aspectRatio = preview.asset.width / preview.asset.height;
    const emptyEdgeRatio = aspectRatio >= 1
        ? 1 - (1 / aspectRatio)
        : 1 - aspectRatio;

    return emptyEdgeRatio >= 0.3;
}

function isLoadablePreview(preview: VibeFullscreenPreviewItem): boolean {
    return Boolean(preview.asset.url) && (preview.asset.kind === 'image' || preview.asset.kind === 'video');
}

function isPreviewLoading(preview: VibeFullscreenPreviewItem): boolean {
    return isLoadablePreview(preview) && previewLoadStates.value[getPreviewKey(preview)] === 'loading';
}

function getPreviewReadyOpacity(preview: VibeFullscreenPreviewItem): string {
    return previewLoadStates.value[getPreviewKey(preview)] === 'ready' ? 'opacity-100' : 'opacity-0';
}

function settlePreview(preview: VibeFullscreenPreviewItem, state: Exclude<PreviewLoadState, 'loading'>): void {
    previewLoadStates.value = {
        ...previewLoadStates.value,
        [getPreviewKey(preview)]: state,
    };
}
</script>

<template>
    <div
        v-if="visiblePreviews.length"
        data-testid="fullscreen-sheet-next-previews"
        class="absolute inset-x-0 bottom-0 z-10 border-t border-twilight-indigo-500/70 bg-prussian-blue-900/95 p-2 shadow-[0_-18px_45px_-24px_rgba(0,0,0,0.9)] backdrop-blur"
    >
        <div class="flex justify-center gap-1 overflow-hidden">
            <button
                v-for="preview in visiblePreviews"
                :key="getPreviewKey(preview)"
                type="button"
                data-testid="fullscreen-sheet-next-preview"
                :data-index="preview.index"
                :aria-label="getPreviewLabel(preview)"
                :title="preview.asset.label"
                class="group relative h-[150px] w-[150px] shrink-0 overflow-hidden border border-twilight-indigo-500/70 bg-prussian-blue-950/80 text-left transition hover:border-smart-blue-400/80 focus:outline-none focus:ring-2 focus:ring-smart-blue-400"
                @click="emit('select', preview.index)"
            >
                <img
                    v-if="preview.asset.kind === 'image'"
                    :src="preview.asset.url ?? undefined"
                    :alt="preview.asset.label"
                    class="h-full w-full transition-opacity duration-200"
                    :class="[getPreviewFitClass(preview), getPreviewReadyOpacity(preview)]"
                    @error="settlePreview(preview, 'error')"
                    @load="settlePreview(preview, 'ready')"
                >
                <video
                    v-else-if="preview.asset.kind === 'video'"
                    :src="preview.asset.url ?? undefined"
                    class="h-full w-full transition-opacity duration-200"
                    :class="[getPreviewFitClass(preview), getPreviewReadyOpacity(preview)]"
                    muted
                    playsinline
                    preload="metadata"
                    @error="settlePreview(preview, 'error')"
                    @loadedmetadata="settlePreview(preview, 'ready')"
                />
                <span v-else class="grid h-full w-full place-items-center bg-prussian-blue-950/90 text-twilight-indigo-200">
                    <ImageIcon class="h-5 w-5" aria-hidden="true" />
                </span>
                <span
                    v-if="preview.asset.kind === 'video'"
                    class="pointer-events-none absolute left-2 top-2 inline-flex h-6 w-6 items-center justify-center bg-prussian-blue-950/75 text-white"
                >
                    <Video class="h-3.5 w-3.5" aria-hidden="true" />
                </span>
                <span
                    v-if="isPreviewLoading(preview)"
                    data-testid="fullscreen-sheet-next-preview-spinner"
                    class="pointer-events-none absolute inset-0 grid place-items-center bg-prussian-blue-950/45"
                >
                    <Loader2 class="h-5 w-5 animate-spin text-white/80" aria-hidden="true" />
                </span>
                <span class="pointer-events-none absolute bottom-2 right-2 bg-prussian-blue-950/80 px-2 py-1 text-[10px] font-semibold text-white/90">
                    {{ preview.index + 1 }} / {{ totalItems }}
                </span>
            </button>
        </div>
    </div>
</template>
