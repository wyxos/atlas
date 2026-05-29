<script setup lang="ts">
import { LockKeyhole, LockKeyholeOpen } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

const props = withDefaults(defineProps<{
    canUnlock?: boolean;
    unlockPageLoading?: (() => void) | null;
}>(), {
    canUnlock: false,
    unlockPageLoading: null,
});

function handleUnlock(event: MouseEvent): void {
    if (!props.canUnlock || !props.unlockPageLoading) {
        return;
    }

    props.unlockPageLoading();

    if (event.detail > 0 && event.currentTarget instanceof HTMLElement) {
        event.currentTarget.blur();
    }
}
</script>

<template>
    <section
        aria-live="polite"
        class="pointer-events-auto flex w-[calc(100vw-2rem)] max-w-[30rem] flex-col gap-3 border border-warning-400/45 bg-prussian-blue-900/94 px-4 py-4 text-left shadow-[0_28px_90px_-45px_rgba(0,0,0,0.95)] backdrop-blur-[18px] sm:w-auto sm:flex-row sm:items-center sm:justify-between sm:px-5"
        data-testid="browse-fullscreen-page-loading-locked"
    >
        <div class="flex min-w-0 items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center border border-warning-300/45 bg-warning-500/14 text-warning-100">
                <LockKeyhole :size="18" stroke-width="1.9" aria-hidden="true" />
            </span>
            <span class="grid min-w-0 gap-1">
                <span class="text-sm font-semibold text-white">
                    More content loading is locked
                </span>
                <span class="text-xs leading-5 text-twilight-indigo-100">
                    Unlock page loading to keep moving through the remaining feed.
                </span>
            </span>
        </div>

        <Button
            v-if="canUnlock && unlockPageLoading"
            type="button"
            variant="outline"
            size="sm"
            class="shrink-0 border-warning-400 bg-warning-500/15 text-warning-100 hover:border-warning-300 hover:bg-warning-500/25 hover:text-warning-100"
            data-testid="browse-fullscreen-page-loading-unlock"
            @click.stop="handleUnlock"
        >
            <LockKeyholeOpen :size="15" stroke-width="1.9" aria-hidden="true" />
            Unlock loading
        </Button>
    </section>
</template>

<style scoped>
:global([data-testid="vibe-forward-fill-placeholder"]) {
    visibility: hidden;
}
</style>
