<script setup lang="ts">
import { AlertTriangle } from 'lucide-vue-next';
import type { FileProcessingFailure } from '@/types/file';

withDefaults(defineProps<{
    failure: FileProcessingFailure | null;
    compact?: boolean;
}>(), {
    compact: false,
});
</script>

<template>
    <div
        v-if="failure"
        role="alert"
        data-test="file-processing-failure"
        :data-testid="compact ? 'browse-fullscreen-processing-failure' : undefined"
        :class="[
            'flex min-w-0 border border-danger-400/60 bg-danger-400/15 text-danger-100',
            compact ? 'max-w-[min(26rem,45vw)] items-center gap-2 px-2 py-1' : 'gap-3 p-3',
        ]"
    >
        <AlertTriangle :size="compact ? 14 : 20" :class="['shrink-0 text-danger-300', { 'mt-0.5': !compact }]" />
        <div class="min-w-0">
            <div :class="['font-semibold text-danger-200', { 'text-xs': compact }]">
                {{ failure.title }}
            </div>
            <div :class="compact ? 'truncate text-[11px] text-danger-100' : 'mt-1 wrap-break-word text-sm leading-relaxed text-danger-100'">
                {{ failure.message }}
            </div>
        </div>
    </div>
</template>
