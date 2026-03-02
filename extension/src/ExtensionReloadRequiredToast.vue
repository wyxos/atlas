<script setup lang="ts">
import { RefreshCcw, TriangleAlert } from 'lucide-vue-next';
import { ToastDescription, ToastProvider, ToastRoot, ToastTitle, ToastViewport } from 'reka-ui';
import { Button } from '@/components/ui/button';

interface Props {
    open: boolean;
}

defineProps<Props>();

const emit = defineEmits<{
    reload: [];
}>();

function emitReload(): void {
    emit('reload');
}
</script>

<template>
    <ToastProvider :duration="2147483647" :disable-swipe="true" label="Extension status">
        <ToastRoot
            :open="open"
            type="foreground"
            data-slot="extension-reload-toast"
            class="pointer-events-auto rounded-lg border border-amber-400/60 bg-prussian-blue-700 px-4 py-3 shadow-lg"
            @escape-key-down.prevent
        >
            <div class="flex items-start gap-3">
                <TriangleAlert class="mt-0.5 size-4 shrink-0 text-amber-300" />
                <div class="space-y-1">
                    <ToastTitle class="text-sm font-semibold text-regal-navy-100">
                        Extension reload required
                    </ToastTitle>
                    <ToastDescription class="text-xs text-twilight-indigo-200">
                        Atlas has an update ready. Reload the extension to apply it.
                    </ToastDescription>
                </div>
            </div>

            <div class="mt-3 flex justify-end">
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 border-amber-400/70 text-amber-100 hover:bg-amber-500/20 hover:text-amber-50"
                    @click="emitReload"
                >
                    <RefreshCcw class="size-3.5" />
                    Reload Extension
                </Button>
            </div>
        </ToastRoot>

        <ToastViewport
            class="pointer-events-none fixed top-4 right-4 z-50 flex w-[min(92vw,360px)] max-w-full flex-col gap-2 outline-none"
        />
    </ToastProvider>
</template>
