<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { LoadedItemsBulkAction } from '@/lib/tabContentLoadedItemsBulkActions';

const props = defineProps<{
    action: LoadedItemsBulkAction | null;
    itemCount: number;
}>();

const emit = defineEmits<{
    cancel: [];
    confirm: [];
}>();

const actionLabel = computed(() => {
    if (props.action === 'blacklist') {
        return 'Blacklist';
    }

    if (props.action === 'like') {
        return 'Like';
    }

    return 'Love';
});
const actionVariant = computed<'destructive' | 'default'>(() => props.action === 'blacklist' ? 'destructive' : 'default');
const itemLabel = computed(() => props.itemCount === 1 ? '1 loaded item' : `${props.itemCount} loaded items`);

function updateOpen(value: boolean): void {
    if (!value && props.action !== null) {
        emit('cancel');
    }
}
</script>

<template>
    <Dialog :model-value="action !== null" @update:model-value="updateOpen">
        <DialogContent
            class="sm:max-w-[460px] bg-prussian-blue-600 border-twilight-indigo-500/50"
            data-test="loaded-items-batch-action-confirm"
        >
            <DialogHeader>
                <DialogTitle>{{ actionLabel }} all loaded items?</DialogTitle>
                <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                    This will queue {{ actionLabel.toLowerCase() }} for {{ itemLabel }} in the current tab.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <Button variant="outline" data-test="loaded-items-batch-action-cancel" @click="emit('cancel')">
                    Cancel
                </Button>
                <Button :variant="actionVariant" data-test="loaded-items-batch-action-confirm-button" @click="emit('confirm')">
                    Confirm
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
