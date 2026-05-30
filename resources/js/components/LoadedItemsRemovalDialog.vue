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

const props = defineProps<{
    open: boolean;
    itemCount: number;
    removing?: boolean;
}>();

const emit = defineEmits<{
    cancel: [];
    confirm: [];
}>();

const itemLabel = computed(() => props.itemCount === 1 ? '1 loaded item' : `${props.itemCount} loaded items`);

function updateOpen(value: boolean): void {
    if (!value && props.open && !props.removing) {
        emit('cancel');
    }
}
</script>

<template>
    <Dialog :model-value="open" @update:model-value="updateOpen">
        <DialogContent
            class="sm:max-w-[460px] bg-prussian-blue-600 border-twilight-indigo-500/50"
            data-test="loaded-items-removal-confirm"
        >
            <DialogHeader>
                <DialogTitle>Remove loaded items from this tab?</DialogTitle>
                <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                    This will remove {{ itemLabel }} from Vibe, then detach those files from the current tab. Files stay in your library and any other tabs.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <Button
                    variant="outline"
                    data-test="loaded-items-removal-cancel"
                    :disabled="removing"
                    @click="emit('cancel')"
                >
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    data-test="loaded-items-removal-confirm-button"
                    :disabled="removing"
                    @click="emit('confirm')"
                >
                    {{ removing ? 'Removing...' : 'Remove' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
