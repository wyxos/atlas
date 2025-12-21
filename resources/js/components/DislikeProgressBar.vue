<script setup lang="ts">
import { ThumbsDown, Pause, Play } from 'lucide-vue-next';

interface Props {
    progress?: number;
    countdown?: string;
    isFrozen?: boolean;
    isHovered?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    progress: 60,
    countdown: '05:00',
    isFrozen: false,
    isHovered: false,
});
</script>

<template>
    <div class="absolute inset-0 flex items-center justify-center z-40 pointer-events-none" :class="{ 'opacity-20': props.isHovered }">
        <div class="w-full relative flex items-center gap-2 bg-black/80 rounded">
            <!-- Progress Bar Container -->
            <div
                class="relative h-10  overflow-hidden border border-danger-500/50 shadow-xl flex-1">
                <!-- Progress Fill (Red) -->
                <div class="absolute inset-0 bg-danger-600 transition-all duration-100 ease-linear"
                    :style="{ width: `${progress}%` }" />

                <!-- Content Overlay (Icon + Countdown) -->
                <div class="absolute inset-0 flex items-center justify-center gap-3 px-4">
                    <!-- Dislike Icon -->
                    <ThumbsDown class="size-5 text-white shrink-0" />

                    <!-- Countdown Text -->
                    <span class="text-white font-mono text-sm font-semibold">
                        {{ countdown }}
                    </span>
                </div>
            </div>

            <!-- Queue Status Icon (outside progress bar, on the right) -->
            <div class="shrink-0" :class="props.isFrozen ? 'text-yellow-400' : 'text-green-400'">
                <Pause v-if="props.isFrozen" :size="20" class="animate-pulse" />
                <Play v-else :size="20" />
            </div>
        </div>
    </div>
</template>

