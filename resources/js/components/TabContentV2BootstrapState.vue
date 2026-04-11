<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

defineProps<{
    hasError: boolean;
    isLoading: boolean;
    onRetry: () => void | Promise<void>;
}>();
</script>

<template>
    <div class="flex h-full min-h-0 items-center justify-center px-6 py-10">
        <div class="flex max-w-md flex-col items-center gap-4 text-center" data-test="browse-tab-bootstrap-state">
            <Loader2 v-if="isLoading" class="size-8 animate-spin text-smart-blue-200" data-test="browse-tab-bootstrap-loading" />
            <div class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-smart-blue-200/80" data-test="browse-tab-bootstrap-status">
                    {{ hasError ? 'Browse tab failed to load' : 'Loading browse tab' }}
                </p>
                <p class="text-sm text-[#f7f1ea]/70" data-test="browse-tab-bootstrap-message">
                    {{ hasError ? 'The active browse tab could not be restored. Retry to load it again.' : 'Restoring the active tab and browse session.' }}
                </p>
            </div>
            <Button v-if="hasError" size="sm" data-test="browse-tab-bootstrap-retry" @click="onRetry">
                Retry
            </Button>
        </div>
    </div>
</template>
