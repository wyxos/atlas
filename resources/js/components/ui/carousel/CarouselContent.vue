<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import { useCarousel } from './useCarousel';

interface Props {
    class?: HTMLAttributes['class'];
}

const props = defineProps<Props>();
const { carouselRef, orientation } = useCarousel();
</script>

<template>
    <div ref="carouselRef" class="overflow-hidden">
        <div
            data-slot="carousel-content"
            :class="cn(
                'flex',
                orientation === 'horizontal' ? '-ml-4' : '-mt-4 flex-col',
                props.class,
            )"
        >
            <slot />
        </div>
    </div>
</template>
