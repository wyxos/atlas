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
                    This file is already downloaded. Update the reaction, pull down a fresh copy, or leave everything as-is.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2 sm:justify-end">
                <Button variant="outline" @click="closeDialog">
                    Leave it as-is
                </Button>
                <Button variant="outline" @click="chooseReact">
                    Update reaction
                </Button>
                <Button @click="chooseRedownload">
                    Update and download again
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
