<script setup lang="ts">
import { useEventListener } from '@vueuse/core';
import { X } from 'lucide-vue-next';
import { computed, getCurrentInstance, ref, watch } from 'vue';
import type { ContainerPillTarget } from '@/composables/useContainerPillInteractions';
import type { FeedItem } from '@/composables/useTabs';
import {
    Carousel,
    CarouselContent,
    CarouselItem,
    CarouselNext,
    CarouselPrevious,
} from '@/components/ui/carousel';

interface Props {
    open: boolean;
    container: ContainerPillTarget | null;
    items: FeedItem[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const drawerPanel = ref<HTMLElement | null>(null);
const renderedContainer = ref<ContainerPillTarget | null>(props.container);
const renderedItems = ref<FeedItem[]>(props.items);
const shouldRenderDrawer = ref(props.open);
const instance = getCurrentInstance();
const drawerTitleId = `container-related-items-title-${instance?.uid ?? 'unknown'}`;
const drawerDescriptionId = `container-related-items-description-${instance?.uid ?? 'unknown'}`;

watch(
    () => props.open,
    (open) => {
        if (open) {
            shouldRenderDrawer.value = true;
        }
    },
);

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

function closeDrawer(): void {
    emit('update:open', false);
}

function handleAfterLeave(): void {
    if (!props.open) {
        shouldRenderDrawer.value = false;
    }
}

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

function shouldIgnoreOutsideClick(target: EventTarget | null): boolean {
    if (!(target instanceof Element)) {
        return false;
    }

    return target.closest('[data-container-pill-trigger]') !== null;
}

useEventListener('click', (event) => {
    if (!props.open || !drawerPanel.value) {
        return;
    }

    if (shouldIgnoreOutsideClick(event.target) || drawerPanel.value.contains(event.target as Node | null)) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    closeDrawer();
}, { capture: true });

useEventListener('keydown', (event) => {
    if (props.open && event.key === 'Escape') {
        event.preventDefault();
        closeDrawer();
    }
});
</script>

<template>
    <div class="pointer-events-none absolute inset-x-0 bottom-0 z-50 px-3 pb-3" data-test="container-related-items-drawer-shell">
        <Transition name="container-drawer" @after-leave="handleAfterLeave">
            <section
                v-if="shouldRenderDrawer"
                v-show="open"
                ref="drawerPanel"
                role="dialog"
                aria-modal="false"
                :aria-labelledby="drawerTitleId"
                :aria-describedby="drawerDescriptionId"
                class="pointer-events-auto relative max-h-[48vh] overflow-hidden rounded-t-xl border border-b-0 border-white/10 border-t-2 border-twilight-indigo-500 bg-prussian-blue-600 shadow-2xl"
                data-test="container-related-items-drawer"
                @click.stop
                @mousedown.stop
                @contextmenu.prevent.stop
                @auxclick.stop
            >
                <div class="sr-only">
                    <h2 :id="drawerTitleId" data-test="container-related-items-title">
                        Related items
                    </h2>
                    <p :id="drawerDescriptionId" data-test="container-related-items-description">
                        {{ drawerDescription }}
                    </p>
                </div>
                <button
                    type="button"
                    class="absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-smart-blue-400 focus:ring-offset-2 focus:ring-offset-prussian-blue-600 focus:outline-hidden text-twilight-indigo-100"
                    aria-label="Close related items drawer"
                    data-test="container-related-items-close"
                    @click="closeDrawer"
                >
                    <X class="size-4" />
                </button>

                <div class="px-6 py-5">
                    <Carousel
                        class="w-full px-10"
                        :opts="{ align: 'start', containScroll: 'trimSnaps' }"
                        data-test="container-related-items-carousel"
                    >
                        <CarouselContent
                            class="pb-2"
                            data-test="container-related-items-track"
                        >
                            <CarouselItem
                                v-for="(item, index) in visibleItems"
                                :key="item.id"
                                class="basis-36"
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
                            </CarouselItem>
                        </CarouselContent>
                        <CarouselPrevious
                            class="left-0 bg-prussian-blue-500/95"
                        />
                        <CarouselNext
                            class="right-0 bg-prussian-blue-500/95"
                        />
                    </Carousel>
                </div>
            </section>
        </Transition>
    </div>
</template>

<style scoped>
.container-drawer-enter-active,
.container-drawer-leave-active {
    transition: transform 0.24s ease, opacity 0.24s ease;
    will-change: transform, opacity;
}

.container-drawer-enter-from,
.container-drawer-leave-to {
    opacity: 0;
    transform: translateY(100%);
}
</style>
