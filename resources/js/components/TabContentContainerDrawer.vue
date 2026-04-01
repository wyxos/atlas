<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import type { ContainerPillTarget } from '@/composables/useContainerPillInteractions';
import type { FeedItem } from '@/composables/useTabs';

interface Props {
    open: boolean;
    container: ContainerPillTarget | null;
    items: FeedItem[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const renderedContainer = ref<ContainerPillTarget | null>(props.container);
const renderedItems = ref<FeedItem[]>(props.items);

watch(
    () => [props.open, props.container] as const,
    ([open, container]) => {
        if (open || container) {
            renderedContainer.value = container;
        }
    },
    { immediate: true },
);

watch(
    () => [props.open, props.items] as const,
    ([open, items]) => {
        if (open || items.length > 0) {
            renderedItems.value = items;
        }
    },
    { immediate: true },
);

const visibleContainer = computed(() => props.container ?? renderedContainer.value);
const visibleItems = computed(() => (
    props.items.length > 0 ? props.items : renderedItems.value
));

const containerLabel = computed(() => (
    visibleContainer.value?.browse_tab?.label?.trim()
    || visibleContainer.value?.type
    || 'container'
));

const itemCountLabel = computed(() => (
    visibleItems.value.length === 1 ? '1 related item' : `${visibleItems.value.length} related items`
));

const drawerDescription = computed(() => (
    `${itemCountLabel.value} from ${containerLabel.value}.`
));

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
    <Sheet :open="open" :modal="false" @update:open="emit('update:open', $event)">
        <SheetContent
            side="bottom"
            :show-overlay="false"
            class="max-h-[48vh] border-x-0 px-0 pb-0 pt-0"
            data-test="container-related-items-drawer"
        >
            <div class="sr-only">
                <SheetTitle data-test="container-related-items-title">
                    Related items
                </SheetTitle>
                <SheetDescription data-test="container-related-items-description">
                    {{ drawerDescription }}
                </SheetDescription>
            </div>
            <div class="px-6 py-5">
                <div
                    class="overflow-x-auto pb-2"
                    data-test="container-related-items-track"
                >
                    <div class="flex min-w-max gap-4">
                        <div
                            v-for="(item, index) in visibleItems"
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
