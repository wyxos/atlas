<script setup lang="ts">
import { computed, ref } from 'vue';
import { useToast } from 'vue-toastification';
import { useQueue } from '@/composables/useQueue';
import { Shield, Plus, Eye } from 'lucide-vue-next';
import ModerationReviewModal from '@/components/moderation/ModerationReviewModal.vue';

const toast = useToast();
const queue = useQueue();

interface PreviewItem {
    id: number;
    action_type: string;
    thumbnail?: string;
}

interface Props {
    toastId: string;
    previews: PreviewItem[];
    totalCount: number;
    allFiles: PreviewItem[]; // All moderated files for the modal
}

const props = defineProps<Props>();

const isModalOpen = ref(false);

// Show up to 5 previews, with + icon if more
const visiblePreviews = computed(() => props.previews.slice(0, 5));
const hasMore = computed(() => props.totalCount > 5);

/**
 * Toast container classes - danger theme for moderation.
 */
const toastClasses = computed(() => {
    return 'batch-moderation-toast group relative flex items-center gap-3 rounded-lg border border-danger-500/50 bg-danger-600 p-4 shadow-xl';
});

/**
 * Text color classes - danger theme.
 */
const textColor = computed(() => {
    return 'text-danger-100';
});

/**
 * Secondary text color classes - danger theme.
 * Use white for better visibility on red background.
 */
const secondaryTextColor = computed(() => {
    return 'text-white';
});

/**
 * Icon color classes - danger theme.
 * Use white for better visibility on red background.
 */
const iconColor = computed(() => {
    return 'text-white';
});

/**
 * Dismiss button classes - danger theme.
 */
const dismissButtonClasses = computed(() => {
    return 'shrink-0 rounded p-1 text-white transition-colors hover:bg-white/20 hover:text-white';
});

/**
 * Review button classes - danger theme.
 * Use white text for better visibility on red background.
 */
const reviewButtonClasses = computed(() => {
    return 'flex items-center gap-1 rounded bg-white/20 px-2 py-1 text-xs font-medium text-white transition-colors hover:bg-white/30 hover:text-white';
});

/**
 * Calculate the overlap offset in pixels for each preview based on its index.
 * Image 1: 0px offset (100% visible)
 * Image 2: 12.8px offset (20% of 64px, 80% visible, 20% behind image 1)
 * Image 3: 25.6px offset (40% of 64px, 60% visible, 40% behind image 2)
 * Image 4: 38.4px offset (60% of 64px, 40% visible, 60% behind image 3)
 * Image 5: 51.2px offset (80% of 64px, 20% visible, 80% behind image 4)
 *
 * The offset is calculated as a percentage of the image width (64px).
 * Each subsequent image is offset by 20% more than the previous one.
 */
function getPreviewOffset(index: number): number {
    return (index * 20 / 100) * 64; // Convert percentage to pixels: 0px, 12.8px, 25.6px, 38.4px, 51.2px
}

/**
 * Calculate the total width needed for all previews and the plus icon.
 * Each image is 64px wide, and they overlap by 80% of the previous image.
 * Image 1: 64px (full width)
 * Image 2: 64px - 12.8px (20% of 64px) = 51.2px additional width
 * Image 3: 64px - 25.6px (40% of 64px) = 38.4px additional width
 * Image 4: 64px - 38.4px (60% of 64px) = 25.6px additional width
 * Image 5: 64px - 51.2px (80% of 64px) = 12.8px additional width
 * Total: 64 + 51.2 + 38.4 + 25.6 + 12.8 = 192px
 * Plus icon: +64px = 256px total
 */
const previewsContainerWidth = computed(() => {
    if (visiblePreviews.value.length === 0) return '64px';

    // Calculate width based on overlapping images
    // First image takes full 64px, each subsequent adds (64px - overlap)
    let totalWidth = 64; // First image
    for (let i = 1; i < visiblePreviews.value.length; i++) {
        const overlap = (i * 20) / 100 * 64; // Overlap in pixels
        totalWidth += (64 - overlap);
    }

    // Add space for plus icon if needed
    if (hasMore.value) {
        totalWidth += 64;
    }

    return `${totalWidth}px`;
});

function handleReview(): void {
    // Freeze all queues before opening the modal
    queue.freezeAll();
    // Open the moderation review modal
    isModalOpen.value = true;
}

function handleModalClose(open: boolean): void {
    isModalOpen.value = open;
    // Unfreeze queues when modal closes (with 2-second delay)
    if (!open) {
        queue.unfreezeAll();
    }
}

function handleDismiss(): void {
    toast.dismiss(props.toastId);
}
</script>

<template>
    <div :class="toastClasses" class="flex! gap-4!">
        <!-- Overlapping Preview Thumbnails -->
        <div class="relative shrink-0 flex items-center" :style="{ width: previewsContainerWidth, height: '64px' }">
            <div
                v-for="(preview, index) in visiblePreviews"
                :key="preview.id"
                class="relative rounded object-cover border-2 border-danger-500/50"
                :style="{
                    width: '64px',
                    height: '64px',
                    zIndex: visiblePreviews.length - index,
                    marginLeft: index === 0 ? '0' : `-${getPreviewOffset(index)}px`,
                }"
            >
                <img
                    v-if="preview.thumbnail"
                    :src="preview.thumbnail"
                    :alt="`File ${preview.id}`"
                    class="size-full rounded object-cover"
                />
                <div
                    v-else
                    class="size-full rounded bg-danger-500/20 flex items-center justify-center"
                >
                    <span class="text-xs text-white">#{{ preview.id }}</span>
                </div>
            </div>
            <!-- + Icon positioned to the right of the last preview -->
            <div
                v-if="hasMore"
                class="relative rounded flex flex-col items-center justify-center border-2 ml-2 border-danger-500/50 bg-danger-500/20"
                style="width: 64px; height: 64px;"
            >
                <Plus class="size-6 text-white" />
                <span class="text-xs font-bold mt-1 text-white">{{ totalCount - 5 }}</span>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0">
                    <div :class="['shrink-0', iconColor]">
                        <Shield class="size-4" />
                    </div>
                    <p :class="['text-sm font-semibold truncate', textColor]">
                        {{ totalCount }} file{{ totalCount !== 1 ? 's' : '' }} moderated
                    </p>
                </div>
                <button
                    @click="handleDismiss"
                    :class="dismissButtonClasses"
                    aria-label="Dismiss"
                >
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Actions -->
            <div class="mt-2 flex items-center justify-end gap-2">
                <button @click="handleReview" :class="reviewButtonClasses">
                    <Eye class="size-3" />
                    Review
                </button>
            </div>
        </div>
    </div>

    <!-- Moderation Review Modal -->
    <ModerationReviewModal :open="isModalOpen" :files="allFiles" @update:open="handleModalClose" />
</template>

<style scoped>
.batch-moderation-toast {
    min-width: 300px;
    max-width: 600px;
}
</style>

