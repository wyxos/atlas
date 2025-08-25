<script lang="ts" setup>
import FileReactions from '@/components/audio/FileReactions.vue';
import { useSeenStatus } from '@/composables/useSeenStatus';
import type { BrowseItem } from '@/types/browse';
import axios from 'axios';
import { RotateCcw, ExternalLink } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

interface Props {
    item: BrowseItem;
    downloadProgress?: number;
    isDownloaded: boolean;
    isLoading?: boolean;
    // Counts (excluding this item) for badges
    postRelatedCount?: number; // other items with same postId
    userRelatedCount?: number; // other items with same username
}

const props = defineProps<Props>();

const emit = defineEmits<{
    favorite: [item: BrowseItem, event: Event];
    like: [item: BrowseItem, event: Event];
    dislike: [item: BrowseItem, event: Event];
    laughedAt: [item: BrowseItem, event: Event];
    altClick: [item: BrowseItem];
    altMiddleClick: [item: BrowseItem];
    altRightClick: [item: BrowseItem];
    leftClick: [item: BrowseItem];
    contextmenu: [event: MouseEvent];
    postBadgeEnter: [item: BrowseItem];
    postBadgeLeave: [];
}>();

const handleLeftClick = () => {
    // Navigate to single file view with preserved state
    emit('leftClick', props.item);
};

const handleAltClick = () => {
    emit('altClick', props.item);
};

const handleAltMiddleClick = () => {
    emit('altMiddleClick', props.item);
};

const handleAltRightClick = () => {
    emit('altRightClick', props.item);
};

// Utility function to detect if the file is a video based on its URL
const isVideo = computed(() => {
    const src = props.item.src.toLowerCase();
    return (
        src.includes('.mp4') ||
        src.includes('.webm') ||
        src.includes('.mov') ||
        src.includes('.avi') ||
        src.includes('.mkv') ||
        src.includes('.wmv') ||
        src.includes('.m4v')
    );
});

// Utility function to detect if the file is an image
const isImage = computed(() => {
    const src = props.item.src.toLowerCase();
    return (
        src.includes('.jpg') ||
        src.includes('.jpeg') ||
        src.includes('.png') ||
        src.includes('.gif') ||
        src.includes('.webp') ||
        src.includes('.svg') ||
        src.includes('.bmp') ||
        !isVideo.value
    );
});

// Use the seen status composable
const { markAsSeen } = useSeenStatus();
const hasMarkedPreview = ref(false);

// Error handling and retry state
const hasError = ref(false);
const errorStatus = ref<number | null>(null);
const retryAttempts = ref(0);
const currentSrc = ref<string>('');

watch(
    () => props.item.src,
    (newSrc) => {
        hasError.value = false;
        errorStatus.value = null;
        retryAttempts.value = 0;
        currentSrc.value = newSrc;
    },
    { immediate: true },
);

const handleMediaError = async () => {
    hasError.value = true;
    errorStatus.value = null; // unknown until probe
    try {
        const { data } = await axios.get(route('link.check'), { params: { url: props.item.src } });
        errorStatus.value = data?.status ?? 0;
    } catch {
        errorStatus.value = 0; // unknown
    }
};

const retryLoad = async () => {
    if (errorStatus.value === 404) return; // don't retry confirmed 404
    try {
        const { data } = await axios.get(route('link.check'), { params: { url: props.item.src } });
        if (data?.status === 404) {
            errorStatus.value = 404;
            return;
        }
    } catch {}

    retryAttempts.value += 1;
    const ts = Date.now();
    try {
        const u = new URL(currentSrc.value || props.item.src, window.location.origin);
        u.searchParams.set('_r', `${ts}-${retryAttempts.value}`);
        currentSrc.value = u.toString();
    } catch {
        // If URL constructor fails due to cross-origin, fall back to simple query concat
        const sep = (currentSrc.value || props.item.src).includes('?') ? '&' : '?';
        currentSrc.value = (currentSrc.value || props.item.src) + `${sep}_r=${ts}-${retryAttempts.value}`;
    }
    hasError.value = false;
};

// Determine the status badge
const statusBadge = computed(() => {
    if (props.item.seen_file_at) {
        return { text: 'Viewed', class: 'bg-blue-500' };
    }
    if (props.item.seen_preview_at) {
        return { text: 'Previewed', class: 'bg-yellow-500' };
    }
    return { text: 'New', class: 'bg-red-500' };
});

// Check if element is within the viewport
const isElementInViewport = (el) => {
    const rect = el.getBoundingClientRect();
    return (
        rect.top >= 0 &&
        rect.left >= 0 &&
        rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
        rect.right <= (window.innerWidth || document.documentElement.clientWidth)
    );
};

// Handle marking as seen when preview is loaded (but only if fully visible)
const handlePreviewLoaded = () => {
    if (!props.item.seen_preview_at && !hasMarkedPreview.value && isFullyVisible.value) {
        hasMarkedPreview.value = true;
        markAsSeen(props.item.id, 'preview');
    }
};

// Lazy loading state
const isInViewport = ref(false);
const isFullyVisible = ref(false);

// Observer for lazy loading
let lazyLoadObserver;
// Observer for full visibility tracking
let visibilityObserver;

onMounted(() => {
    // Lazy loading observer - loads content when partially visible
    const lazyLoadOptions = { root: null, rootMargin: '50px', threshold: 0.1 };
    lazyLoadObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                isInViewport.value = true;
                lazyLoadObserver.unobserve(entry.target);
            }
        });
    }, lazyLoadOptions);

    // Full visibility observer - marks as seen when fully visible
    const visibilityOptions = { root: null, rootMargin: '0px', threshold: 1.0 };
    visibilityObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting && entry.intersectionRatio >= 1.0) {
                isFullyVisible.value = true;
                // If content is already loaded and now fully visible, mark as seen
                if (isInViewport.value && !props.item.seen_preview_at && !hasMarkedPreview.value) {
                    hasMarkedPreview.value = true;
                    markAsSeen(props.item.id, 'preview');
                }
            } else {
                isFullyVisible.value = false;
            }
        });
    }, visibilityOptions);

    const element = document.getElementById(`browse-item-${props.item.id}`);
    if (element) {
        lazyLoadObserver.observe(element);
        visibilityObserver.observe(element);
    }
});

onUnmounted(() => {
    if (lazyLoadObserver) {
        lazyLoadObserver.disconnect();
    }
    if (visibilityObserver) {
        visibilityObserver.disconnect();
    }
});

// Intercept alt + back/forward mouse buttons to trigger batch actions without browser navigation
const handleMouseButtons = (event: MouseEvent) => {
    const btn = (event as any).button;
    const postId = (props.item as any)?.listingMetadata?.postId;

    if (!event.altKey || !postId) return;

    if (btn === 3) {
        // Alt + back => block post
        event.preventDefault?.();
        event.stopImmediatePropagation?.();
        try {
            window?.dispatchEvent?.(new CustomEvent('browse:block-post', { detail: { postId } }));
        } catch (e) {
            console.error('Failed to dispatch browse:block-post', e);
        }
    } else if (btn === 4) {
        // Alt + forward => like post
        event.preventDefault?.();
        event.stopImmediatePropagation?.();
        try {
            window?.dispatchEvent?.(new CustomEvent('browse:like-post', { detail: { postId } }));
        } catch (e) {
            console.error('Failed to dispatch browse:like-post', e);
        }
    }
};

// Handle marking as seen when video completes (for videos only) - but only if fully visible
const handleVideoCompleted = () => {
    if (!props.item.seen_preview_at && !hasMarkedPreview.value && isFullyVisible.value) {
        hasMarkedPreview.value = true;
        markAsSeen(props.item.id, 'preview');
    }
};
</script>

<template>
    <div
        :id="`browse-item-${item.id}`"
        class="relative h-full"
        @contextmenu="(event) => !event.altKey && $emit('contextmenu', event)"
        @mousedown.capture="handleMouseButtons"
        @mouseup.capture="handleMouseButtons"
        @auxclick.prevent.stop="handleMouseButtons"
    >
        <!-- Media container with fixed imageHeight -->
        <div>
            <!-- Error placeholder replaces media area with same height -->
            <div
                v-if="hasError"
                :style="{ height: (item.imageHeight ?? 0) + 'px' }"
                class="w-full bg-black/60 flex items-center justify-center"
            >
                		<div class="flex flex-col items-center gap-2 text-white">
                    <div class="flex items-center gap-2">
                        <button class="inline-flex items-center gap-2 rounded bg-white/10 px-3 py-2 backdrop-blur hover:bg-white/20" @click="retryLoad">
                            <RotateCcw :size="18" />
                            <span>Reload</span>
                        </button>
                        		<a :href="props.item.original || props.item.src" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded bg-white/10 px-3 py-2 backdrop-blur hover:bg-white/20">
                            <ExternalLink :size="18" />
                            <span>Open</span>
                        </a>
                    </div>
                    <div v-if="errorStatus === 404" class="text-xs text-red-300">404 Not Found</div>
                </div>
            </div>

            <!-- Image element for image files -->
            <img
                v-else-if="isImage"
                :alt="`Image ${item.id}`"
                :height="item.imageHeight"
                :src="isInViewport ? currentSrc : undefined"
                class="h-full w-full cursor-pointer object-cover"
                loading="lazy"
                @error="handleMediaError"
                @load="handlePreviewLoaded"
                @click.left.exact="handleLeftClick"
                @click.alt.exact.prevent="handleAltClick"
                @click.middle.alt.exact.prevent="handleAltMiddleClick"
                @contextmenu.alt.exact.prevent="handleAltRightClick"
            />

            <!-- Video element for video files -->
            <video
                v-else-if="isVideo"
                :src="isInViewport ? currentSrc : undefined"
                class="h-full w-full cursor-pointer object-cover transition-all duration-500 ease-in-out"
                loop
                muted
                playsinline
                preload="metadata"
                @ended="handleVideoCompleted"
                @error="handleMediaError"
                @loadeddata="handlePreviewLoaded"
                @mouseenter="(e) => e.target.play().catch(() => {})"
                @mouseleave="(e) => e.target.pause()"
                @click.left.exact="handleLeftClick"
                @click.alt.exact.prevent="handleAltClick"
                @click.middle.alt.exact.prevent="handleAltMiddleClick"
                @contextmenu.alt.exact.prevent="handleAltRightClick"
            >
                <source v-if="isInViewport" :src="currentSrc" type="video/mp4" />
                Your browser does not support the video tag.
            </video>
        </div>

        <!-- Status Badge + Separate related badges -->
        <div class="absolute top-2 right-2 z-10 flex items-center gap-1">
            <div
                v-if="props.postRelatedCount && props.postRelatedCount > 1"
                class="rounded bg-black/70 px-1.5 py-0.5 font-bold text-white shadow"
                title="Other items in same post"
                @mouseenter="$emit('postBadgeEnter', props.item)"
                @mouseleave="$emit('postBadgeLeave')"
            >
                P+{{ props.postRelatedCount }}
            </div>
            <div
                v-if="props.userRelatedCount && props.userRelatedCount > 1"
                class="rounded bg-black/70 px-1.5 py-0.5 font-bold text-white shadow"
                title="Other items by same user"
            >
                U+{{ props.userRelatedCount }}
            </div>
            <div :class="statusBadge.class" class="rounded px-2 py-1 text-xs font-medium text-white shadow-lg">
                {{ statusBadge.text }}
            </div>
        </div>

        <!-- Footer area for reactions -->
        <div class="absolute right-0 bottom-0 left-0 flex items-center justify-end p-2" style="height: 32px">
            <div>
                <FileReactions
                    :file="item"
                    :icon-size="16"
                    variant="list"
                    @dislike="(file, event) => $emit('dislike', item, event)"
                    @favorite="(file, event) => $emit('favorite', item, event)"
                    @laughedAt="(file, event) => $emit('laughedAt', item, event)"
                    @like="(file, event) => $emit('like', item, event)"
                />
            </div>
        </div>

        <!-- Download progress bar - positioned at bottom of image area -->
        <div v-if="downloadProgress !== undefined" :style="{ bottom: '32px' }" class="absolute right-0 left-0 bg-black/50">
            <div :style="{ width: downloadProgress + '%' }" class="h-1 bg-blue-500 transition-all duration-300"></div>
            <div class="p-1 text-center text-xs text-white">Downloading... {{ downloadProgress }}%</div>
        </div>


        <!-- Downloaded indicator -->
        <div v-if="isDownloaded" class="absolute top-2 left-2 rounded bg-green-500 px-2 py-1 text-xs text-white">✓ Downloaded</div>
    </div>
</template>
