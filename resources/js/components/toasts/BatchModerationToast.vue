<script setup lang="ts">
import { computed, ref } from 'vue';
import { useToast } from '@/components/ui/toast/use-toast';
import { queueManager } from '@/composables/useQueue';
import { Shield, Eye } from 'lucide-vue-next';
import ModerationReviewModal from '@/components/moderation/ModerationReviewModal.vue';
import ToastPreviewStrip from './ToastPreviewStrip.vue';

const toast = useToast();
const queue = queueManager;
const queueFreeze = queue.freeze;

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
const previewItems = computed(() =>
    props.previews.map((preview) => ({
        key: preview.id,
        label: preview.id,
        thumbnail: preview.thumbnail,
    })),
);

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

function handleReview(): void {
    // Freeze all queues before opening the modal
    queueFreeze.freezeAll();
    // Open the moderation review modal
    isModalOpen.value = true;
}

function handleModalClose(open: boolean): void {
    isModalOpen.value = open;
    // Unfreeze queues when modal closes (with 2-second delay)
    if (!open) {
        queueFreeze.unfreezeAll();
    }
}

function handleDismiss(): void {
    toast.dismiss(props.toastId);
}
</script>

<template>
    <div :class="toastClasses" class="flex! gap-4!">
        <ToastPreviewStrip :items="previewItems" :total-count="totalCount" danger />

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
