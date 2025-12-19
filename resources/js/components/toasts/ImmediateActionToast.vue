<script setup lang="ts">
import { ref } from 'vue';
import { ThumbsDown, Ban, Plus, Eye } from 'lucide-vue-next';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '../ui/dialog';

interface ImmediateActionItem {
    id: number;
    action_type: string;
    thumbnail?: string;
}

interface Props {
    items: ImmediateActionItem[];
    countdown: number;
    onDismiss?: () => void;
}

const props = defineProps<Props>();

const TOAST_DURATION_SECONDS = 5;
const isReviewModalOpen = ref(false);

// Handle hover events to pause/resume countdown (uses centralized timer manager)
function handleMouseEnter(): void {
    const win = window as any;
    // Use new timer manager functions (backward compatible with old names)
    if (win.__timerManagerFreeze) {
        win.__timerManagerFreeze();
    } else if (win.__reactionQueuePauseAll) {
        win.__reactionQueuePauseAll();
    }
}

function handleMouseLeave(): void {
    const win = window as any;
    // Use new timer manager functions (backward compatible with old names)
    if (win.__timerManagerUnfreeze) {
        win.__timerManagerUnfreeze();
    } else if (win.__reactionQueueResumeAll) {
        win.__reactionQueueResumeAll();
    }
}

function getProgress(): number {
    // Calculate progress percentage (0% at start, 100% at end - showing progress toward dismissal)
    return ((TOAST_DURATION_SECONDS - props.countdown) / TOAST_DURATION_SECONDS) * 100;
}

function getActionLabel(): string {
    const hasAutoDislike = props.items.some(item => item.action_type === 'auto_dislike');
    const hasBlacklist = props.items.some(item => item.action_type === 'blacklist');
    
    if (hasAutoDislike && hasBlacklist) {
        return 'auto-disliked and blacklisted';
    } else if (hasAutoDislike) {
        return 'auto-disliked';
    } else if (hasBlacklist) {
        return 'blacklisted';
    }
    return 'processed';
}

function getActionIcon() {
    const hasAutoDislike = props.items.some(item => item.action_type === 'auto_dislike');
    const hasBlacklist = props.items.some(item => item.action_type === 'blacklist');
    
    if (hasBlacklist) {
        return Ban;
    }
    return ThumbsDown;
}

function getActionColor(): string {
    const hasBlacklist = props.items.some(item => item.action_type === 'blacklist');
    return hasBlacklist ? 'text-danger-400' : 'text-gray-400';
}

function getItemActionIcon(item: ImmediateActionItem) {
    return item.action_type === 'blacklist' ? Ban : ThumbsDown;
}

function getItemActionColor(item: ImmediateActionItem): string {
    return item.action_type === 'blacklist' ? 'text-danger-400' : 'text-gray-400';
}

function getItemActionLabel(item: ImmediateActionItem): string {
    if (item.action_type === 'auto_dislike') {
        return 'Auto-disliked';
    } else if (item.action_type === 'blacklist') {
        return 'Blacklisted';
    }
    return item.action_type;
}

function handleDismiss(): void {
    if (props.onDismiss) {
        props.onDismiss();
    }
    // Emit close-toast event for Vue Toastification
    emit('close-toast');
}

function handleReview(): void {
    isReviewModalOpen.value = true;
}

const emit = defineEmits<{
    'close-toast': [];
}>();
</script>

<template>
    <div @mouseenter="handleMouseEnter" @mouseleave="handleMouseLeave"
        class="bg-prussian-blue-800 border border-smart-blue-500/50 rounded-lg p-3 shadow-lg backdrop-blur-sm">
        <div class="flex items-center gap-3 mb-2">
            <!-- Multiple Preview Images (up to 5, then plus icon) - stacked with overlapping effect -->
            <div class="flex gap-2 items-center">
                <div class="stacked-images">
                    <template v-for="(item, index) in items.slice(0, 5)" :key="item.id">
                        <div v-if="item.thumbnail" class="stacked-image">
                            <img :src="item.thumbnail" :alt="`File #${item.id}`" />
                        </div>
                    </template>
                </div>

                <!-- Plus icon for additional items (if more than 5) -->
                <div v-if="items.length > 5" class="stacked-image-plus">
                    <Plus :size="16" class="text-smart-blue-400" />
                </div>
            </div>

            <div class="flex-1 flex gap-4 min-w-0">
                <!-- Action Icon -->
                <component :is="getActionIcon()" :size="20" :class="getActionColor()" />

                <!-- Batch Info and Progress Bar -->
                <div class="flex-1 min-w-0">
                    <div class="text-sm text-white font-medium truncate mb-1">
                        {{ items.length }} files {{ getActionLabel() }}
                    </div>
                    <!-- Progress Bar -->
                    <div class="w-full h-1.5 bg-white/10 rounded-full overflow-hidden">
                        <div class="h-full bg-smart-blue-400"
                            :style="{ width: `${getProgress()}%`, transition: 'width 0.05s linear' }" />
                    </div>
                </div>
            </div>

            <!-- Review Button -->
            <button @click="handleReview"
                class="p-1 rounded hover:bg-black/20 text-white/70 hover:text-white transition-colors"
                aria-label="Review actions">
                <Eye :size="16" />
            </button>
        </div>
    </div>

    <!-- Review Modal -->
    <Dialog v-model:open="isReviewModalOpen">
        <DialogContent class="sm:max-w-[600px] bg-prussian-blue-600">
            <DialogHeader>
                <DialogTitle class="text-twilight-indigo-100">
                    Review Actions
                </DialogTitle>
                <DialogDescription class="text-base mt-2 text-twilight-indigo-200">
                    {{ items.length }} file{{ items.length !== 1 ? 's' : '' }} {{ getActionLabel() }}
                </DialogDescription>
            </DialogHeader>
            <div class="max-h-[60vh] overflow-y-auto space-y-3 mt-4">
                <div v-for="item in items" :key="item.id" class="flex items-center gap-3 p-3 bg-prussian-blue-700/50 rounded-lg border border-smart-blue-500/30">
                    <div v-if="item.thumbnail" class="shrink-0">
                        <img :src="item.thumbnail" :alt="`File #${item.id}`" class="w-16 h-16 object-cover rounded border border-smart-blue-500/50" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-white">
                            File #{{ item.id }}
                        </div>
                        <div class="text-xs text-twilight-indigo-300 mt-1">
                            Action: {{ getItemActionLabel(item) }}
                        </div>
                    </div>
                    <component :is="getItemActionIcon(item)" :size="20" :class="getItemActionColor(item)" />
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
@reference "../../../css/app.css";

.stacked-images {
    --s: 64px;
    /* image size (w-16 = 4rem = 64px) */
    @apply flex items-center relative;
}

.stacked-image {
    @apply relative shrink-0;
}

.stacked-image img {
    width: var(--s);
    height: var(--s);
    @apply object-cover rounded block;
}

/* First image (100% visible) - on top */
.stacked-image:first-child {
    @apply z-[5];
}

.stacked-image:first-child img {
    @apply border-2 border-smart-blue-500 shadow-lg;
}

/* Second image (70% visible) - translate back 30% */
.stacked-image:nth-child(2) {
    @apply z-[4];
    margin-left: calc(var(--s) * -0.3);
}

.stacked-image:nth-child(2) img {
    @apply border border-smart-blue-500/50 shadow-md opacity-90;
}

/* Third image (50% visible) - translate back 50% */
.stacked-image:nth-child(3) {
    @apply z-[3];
    margin-left: calc(var(--s) * -0.5);
}

.stacked-image:nth-child(3) img {
    @apply border border-smart-blue-500/40 shadow opacity-80;
}

/* Fourth image (30% visible) - translate back 70% */
.stacked-image:nth-child(4) {
    @apply z-[2];
    margin-left: calc(var(--s) * -0.7);
}

.stacked-image:nth-child(4) img {
    @apply border border-smart-blue-500/30 shadow-sm opacity-70;
}

/* Fifth image (10% visible) - translate back 90% */
.stacked-image:nth-child(5) {
    @apply z-[1];
    margin-left: calc(var(--s) * -0.9);
}

.stacked-image:nth-child(5) img {
    @apply border border-smart-blue-500/20 shadow-sm opacity-60;
}

/* Plus icon */
.stacked-image-plus {
    @apply relative z-0 rounded border border-smart-blue-500/30 bg-smart-blue-500/20 flex items-center justify-center w-10 h-10;
}
</style>

