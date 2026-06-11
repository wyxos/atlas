<script setup lang="ts">
import { computed } from 'vue';
import { Pause, Play, XCircle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    message: string | null;
    error: string | null;
    progressLabel: string | null;
    progressPercent: number | null;
    canPause?: boolean;
    canResume?: boolean;
    canCancel?: boolean;
    isActionBusy?: boolean;
}>();

const emit = defineEmits<{
    pause: [];
    resume: [];
    cancel: [];
}>();

const hasControls = computed(() => props.canPause === true || props.canResume === true || props.canCancel === true);
</script>

<template>
    <div v-if="message || error" class="border-x border-twilight-indigo-500 bg-prussian-blue-800 px-4 py-2 text-xs">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <p v-if="message" class="text-smart-blue-100">{{ message }}</p>
                <p v-if="error" class="text-danger-100">{{ error }}</p>
            </div>
            <div v-if="hasControls" class="flex shrink-0 items-center gap-1.5" data-test="audio-metadata-run-controls">
                <Button
                    v-if="canPause"
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="audio-metadata-run-pause"
                    :disabled="isActionBusy"
                    aria-label="Pause metadata scan"
                    title="Pause metadata scan"
                    @click="emit('pause')"
                >
                    <Pause class="size-4" aria-hidden="true" />
                    Pause
                </Button>
                <Button
                    v-if="canResume"
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="audio-metadata-run-resume"
                    :disabled="isActionBusy"
                    aria-label="Resume metadata scan"
                    title="Resume metadata scan"
                    @click="emit('resume')"
                >
                    <Play class="size-4" aria-hidden="true" />
                    Resume
                </Button>
                <Button
                    v-if="canCancel"
                    type="button"
                    variant="outline"
                    color="danger"
                    size="sm"
                    data-test="audio-metadata-run-cancel"
                    :disabled="isActionBusy"
                    aria-label="Cancel metadata scan"
                    title="Cancel metadata scan"
                    @click="emit('cancel')"
                >
                    <XCircle class="size-4" aria-hidden="true" />
                    Cancel
                </Button>
            </div>
        </div>
        <div v-if="progressLabel" class="mt-2 flex items-center gap-3 text-blue-slate-300" data-test="audio-metadata-run-progress">
            <span class="shrink-0 tabular-nums">{{ progressLabel }}</span>
            <div
                class="h-1.5 min-w-0 flex-1 rounded-full bg-twilight-indigo-600"
                role="progressbar"
                aria-label="Metadata scan progress"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-valuenow="progressPercent ?? 0"
            >
                <div
                    class="h-1.5 rounded-full bg-smart-blue-400 transition-[width] duration-200"
                    :style="{ width: `${progressPercent ?? 0}%` }"
                />
            </div>
        </div>
    </div>
</template>
