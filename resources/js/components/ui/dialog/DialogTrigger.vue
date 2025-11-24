<script setup lang="ts">
import { inject } from 'vue';

interface Props {
    asChild?: boolean;
}

withDefaults(defineProps<Props>(), {
    asChild: false,
});

const setDialogOpen = inject<(value: boolean) => void>('setDialogOpen');

function handleClick(): void {
    if (setDialogOpen) {
        setDialogOpen(true);
    }
}
</script>

<template>
    <div v-if="!asChild" @click="handleClick">
        <slot />
    </div>
    <div v-else @click="handleClick">
        <slot />
    </div>
</template>

