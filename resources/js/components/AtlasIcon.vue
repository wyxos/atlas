<template>
    <div v-html="svgWithClass" />
</template>

<script setup lang="ts">
import { computed, useAttrs } from 'vue';
import { cn } from '@/lib/utils';
import atlasIconSvg from '@/../svg/atlas-icon.svg?raw';

const attrs = useAttrs();
const svgWithClass = computed(() => {
    const classValue = cn('w-32 h-32', attrs.class as string);
    return atlasIconSvg.replace('<svg', `<svg class="${classValue}"`);
});
</script>

