<script setup lang="ts">
import { ThumbsDown } from 'lucide-vue-next';

interface Props {
    progress?: number;
    countdown?: string;
}

const props = withDefaults(defineProps<Props>(), {
    progress: 60,
    countdown: '05:00',
});
</script>

<template>
    <div class="absolute inset-0 flex items-center justify-center z-40 pointer-events-none">
        <div class="w-full relative">
            <!-- Progress Bar Container -->
            <div
                class="relative h-10 bg-black/80 rounded-lg overflow-hidden border border-danger-500/50 shadow-xl">
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
        </div>
    </div>
</template>

