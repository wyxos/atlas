<script setup lang="ts">
import { Copy, Loader2, TestTube } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from './ui/dialog';

interface Props {
    open: boolean;
    itemId: number | null;
    loading: Map<number, boolean>;
    prompt: string | null;
    updateOpen: (value: boolean) => void;
    copyPrompt: () => void;
    testPrompt: () => void;
    closePrompt: () => void;
}

defineProps<Props>();
</script>

<template>
    <Dialog :model-value="open" @update:model-value="updateOpen">
        <DialogContent class="sm:max-w-[600px] bg-prussian-blue-600">
            <DialogHeader>
                <DialogTitle class="text-twilight-indigo-100">Prompt</DialogTitle>
            </DialogHeader>
            <div class="space-y-4 mt-4">
                <div v-if="itemId !== null && loading.get(itemId)" class="flex items-center gap-2 text-sm text-twilight-indigo-100">
                    <Loader2 :size="16" class="animate-spin" />
                    <span>Loading prompt...</span>
                </div>
                <div v-else-if="prompt" class="space-y-2">
                    <div class="flex-1 whitespace-pre-wrap wrap-break-word text-sm text-twilight-indigo-100 max-h-[60vh] overflow-y-auto">
                        {{ prompt }}
                    </div>
                </div>
                <div v-else class="text-sm text-twilight-indigo-300">
                    No prompt data available
                </div>
            </div>
            <DialogFooter>
                <Button variant="outline" size="sm" @click="copyPrompt" aria-label="Copy prompt">
                    <Copy :size="16" class="mr-2" />
                    Copy
                </Button>
                <Button v-if="prompt" variant="outline" size="sm" @click="testPrompt"
                    aria-label="Test prompt against moderation rules">
                    <TestTube :size="16" class="mr-2" />
                    Test
                </Button>
                <DialogClose as-child>
                    <Button variant="outline" size="sm" @click="closePrompt">
                        Close
                    </Button>
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
