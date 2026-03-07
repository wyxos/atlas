<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface Props {
    open: boolean;
    title: string;
    description: string;
    isDeleting: boolean;
    alsoFromDisk: boolean;
}

defineProps<Props>();

defineEmits<{
    'update:open': [value: boolean];
    'update:alsoFromDisk': [value: boolean];
    confirm: [];
}>();
</script>

<template>
    <Dialog :model-value="open" @update:model-value="$emit('update:open', $event)">
        <DialogContent class="sm:max-w-[425px] bg-prussian-blue-600 border-danger-500/30">
            <DialogHeader>
                <DialogTitle class="text-danger-400">{{ title }}</DialogTitle>
                <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                    {{ description }}
                </DialogDescription>
            </DialogHeader>

            <label class="flex items-center gap-2 mt-1 text-sm text-twilight-indigo-100 cursor-pointer select-none">
                <input
                    type="checkbox"
                    :checked="alsoFromDisk"
                    class="h-4 w-4 rounded border border-twilight-indigo-500 bg-prussian-blue-700 text-danger-400"
                    @change="$emit('update:alsoFromDisk', ($event.target as HTMLInputElement).checked)"
                />
                Also delete file(s) from disk
            </label>

            <DialogFooter>
                <DialogClose as-child>
                    <Button variant="outline" :disabled="isDeleting">
                        Cancel
                    </Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    :loading="isDeleting"
                    :disabled="isDeleting"
                    @click="$emit('confirm')"
                >
                    {{ isDeleting ? 'Removing...' : 'Remove' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
