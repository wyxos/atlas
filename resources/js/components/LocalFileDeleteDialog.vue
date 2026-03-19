<script setup lang="ts">
import Checkbox from '@/components/ui/Checkbox.vue';
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
    deleteRecord: boolean;
    deleting: boolean;
    deleteError?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    filename: null,
    deleteError: null,
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    'update:deleteRecord': [value: boolean];
    confirm: [];
    cancel: [];
}>();

function updateOpen(value: boolean): void {
    emit('update:open', value);
}
</script>

<template>
    <Dialog :model-value="open" @update:model-value="updateOpen">
        <DialogContent class="sm:max-w-[460px] bg-prussian-blue-600 border-danger-500/30">
            <DialogHeader>
                <DialogTitle class="text-danger-400">Delete Local File</DialogTitle>
                <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                    Delete
                    <span v-if="props.filename" class="font-semibold text-danger-300">{{ props.filename }}</span>
                    <span v-else>this file</span>
                    from Atlas storage? This removes the downloaded asset and generated previews from disk.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <Checkbox
                    :model-value="deleteRecord"
                    :disabled="deleting"
                    @update:model-value="(value: boolean) => emit('update:deleteRecord', value)"
                >
                    Also delete the Atlas file record
                </Checkbox>

                <p class="text-xs text-twilight-indigo-300">
                    Leave this off to keep the metadata and reactions in Atlas.
                </p>

                <div
                    v-if="deleteError"
                    class="rounded border border-danger-400 bg-danger-700/20 px-3 py-2 text-sm text-danger-300"
                >
                    {{ deleteError }}
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" :disabled="deleting" @click="emit('cancel')">
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    :disabled="deleting"
                    :loading="deleting"
                    data-test="local-file-delete-confirm"
                    @click="emit('confirm')"
                >
                    {{ deleting ? 'Deleting...' : 'Delete from disk' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
