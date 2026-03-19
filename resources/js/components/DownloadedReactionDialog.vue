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
    updateOpen: (value: boolean) => void;
    chooseReact: () => void;
    chooseRedownload: () => void;
    closeDialog: () => void;
}

defineProps<Props>();
</script>

<template>
    <Dialog :model-value="open" @update:model-value="updateOpen">
        <DialogContent class="sm:max-w-[520px] border-twilight-indigo-500/30 bg-prussian-blue-600">
            <DialogHeader>
                <DialogTitle class="text-twilight-indigo-100">Already saved in Atlas</DialogTitle>
                <DialogDescription class="text-sm text-twilight-indigo-300">
                    This file already exists in Atlas. You can keep this reaction change only, or queue a fresh local copy as well.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2 sm:justify-end">
                <Button variant="outline" @click="closeDialog">
                    Cancel
                </Button>
                <Button variant="outline" @click="chooseReact">
                    Keep reaction
                </Button>
                <Button @click="chooseRedownload">
                    Download again
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
