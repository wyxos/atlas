<script setup lang="ts">
import { Copy, Loader2, ShieldCheck } from 'lucide-vue-next';
import type { File } from '@/types/file';
import { copyToClipboard } from '@/utils/clipboard';
import FilePromptModerationCard from './FilePromptModerationCard.vue';

const props = withDefaults(defineProps<{
    fileData: File;
    prompt?: string | null;
    isPromptLoading?: boolean;
}>(), {
    prompt: null,
    isPromptLoading: false,
});

const emit = defineEmits<{
    'test-prompt': [prompt: string];
}>();

async function handleCopyPrompt(): Promise<void> {
    if (!props.prompt) {
        return;
    }

    try {
        await copyToClipboard(props.prompt, 'Prompt', { showToast: false });
    } catch {
        // Ignore clipboard errors; there is no fallback UI in this sheet.
    }
}
</script>

<template>
    <div class="space-y-2" data-test="file-prompt">
        <div class="flex items-center justify-between gap-3">
            <div class="font-semibold text-white">Prompt</div>
            <button
                v-if="prompt"
                type="button"
                class="shrink-0 rounded p-1 text-white/80 hover:bg-prussian-blue-700 hover:text-white"
                aria-label="Copy prompt"
                data-test="copy-prompt"
                @click="handleCopyPrompt"
            >
                <Copy :size="16" />
            </button>
        </div>
        <FilePromptModerationCard :file-data="fileData" />
        <div v-if="isPromptLoading" class="flex items-center gap-2 text-sm text-twilight-indigo-100">
            <Loader2 :size="16" class="animate-spin" />
            <span>Loading prompt...</span>
        </div>
        <div v-else-if="prompt" class="max-h-[28rem] overflow-y-auto whitespace-pre-wrap wrap-break-word rounded border border-twilight-indigo-500/45 bg-prussian-blue-900/45 p-3 text-sm text-twilight-indigo-100">
            {{ prompt }}
        </div>
        <button
            v-if="prompt"
            type="button"
            class="inline-flex items-center gap-2 rounded border border-smart-blue-400/60 bg-smart-blue-500/15 px-3 py-2 text-sm font-semibold text-smart-blue-100 transition hover:border-smart-blue-300 hover:bg-smart-blue-500/25 focus:outline-none focus:ring-2 focus:ring-smart-blue-300"
            data-test="file-prompt-test"
            @click="emit('test-prompt', prompt)"
        >
            <ShieldCheck :size="16" />
            <span>Test</span>
        </button>
        <div v-else class="text-sm text-twilight-indigo-300">
            No prompt data available
        </div>
    </div>
</template>
