<script setup lang="ts">
import { Sheet, SheetContent } from '@/components/ui/sheet';
import type { ContainerPillTarget } from '@/composables/useContainerPillInteractions';
import type { FeedItem } from '@/composables/useTabs';

interface Props {
    open: boolean;
    container: ContainerPillTarget | null;
    items: FeedItem[];
}

defineProps<Props>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

function handleDrawerVideoLoadedMetadata(event: Event): void {
    const video = event.target as HTMLVideoElement | null;
    if (!video) {
        return;
    }

    const duration = Number.isFinite(video.duration) ? video.duration : 0;
    const previewTime = duration > 0 ? Math.min(0.1, duration / 2) : 0;

    try {
        video.currentTime = previewTime;
    } catch {
        // Ignore failed seeks on metadata-only loads.
    }
}
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent
            side="bottom"
            class="max-h-[48vh] border-x-0 px-0 pb-0 pt-0"
            data-test="container-related-items-drawer"
        >
            <div class="px-6 py-5">
                <div
                    class="overflow-x-auto pb-2"
                    data-test="container-related-items-track"
                >
                    <div class="flex min-w-max gap-4">
                        <div
                            v-for="(item, index) in items"
                            :key="item.id"
                            class="w-36 shrink-0"
                            :data-test="`container-related-item-${index}`"
                        >
                            <div class="aspect-square overflow-hidden rounded-sm border border-border bg-black/30">
                                <video
                                    v-if="item.type === 'video' && item.preview"
                                    :src="item.preview"
                                    class="h-full w-full object-cover pointer-events-none"
                                    muted
                                    playsinline
                                    preload="metadata"
                                    @loadedmetadata="handleDrawerVideoLoadedMetadata"
                                />
                                <img
                                    v-else-if="item.preview"
                                    :src="item.preview"
                                    :alt="`Related item ${item.id}`"
                                    class="h-full w-full object-cover pointer-events-none"
                                />
                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center px-3 text-center text-xs text-muted-foreground"
                                >
                                    No preview
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
