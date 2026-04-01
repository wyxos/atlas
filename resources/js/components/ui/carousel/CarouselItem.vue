<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import { useCarousel } from './useCarousel';

interface Props {
    class?: HTMLAttributes['class'];
}

const props = defineProps<Props>();
const { orientation } = useCarousel();
</script>

<template>
    <div
        role="group"
        aria-roledescription="slide"
        data-slot="carousel-item"
        :class="cn(
            'min-w-0 shrink-0 grow-0 basis-full',
            orientation === 'horizontal' ? 'pl-4' : 'pt-4',
            props.class,
        )"
    >
        <slot />
    </div>
</template>
