<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface Props {
    open: boolean;
    filename?: string | null;
    deleting: boolean;
    deleteError?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    filename: null,
    deleteError: null,
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [];
    cancel: [];
}>();

function updateOpen(value: boolean): void {
    emit('update:open', value);
}
</script>

<template>
    <Dialog :model-value="open" @update:model-value="updateOpen">
        <DialogContent
            class="sm:max-w-[460px] bg-prussian-blue-600 border-danger-500/30"
            data-test="local-file-delete-dialog"
        >
            <DialogHeader>
                <DialogTitle class="text-danger-400">Delete file</DialogTitle>
                <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                    Delete
                    <span v-if="props.filename" class="font-semibold text-danger-300">{{ props.filename }}</span>
                    <span v-else>this file</span>
                    from Atlas? This permanently removes the Atlas file record and any stored asset or generated previews when present.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div
                    v-if="deleteError"
                    class="rounded border border-danger-400 bg-danger-700/20 px-3 py-2 text-sm text-danger-300"
                >
                    {{ deleteError }}
                </div>
            </div>

            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="deleting"
                    data-test="local-file-delete-cancel"
                    @click="emit('cancel')"
                >
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    :disabled="deleting"
                    :loading="deleting"
                    data-test="local-file-delete-confirm"
                    @click="emit('confirm')"
                >
                    {{ deleting ? 'Deleting...' : 'Delete file' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
