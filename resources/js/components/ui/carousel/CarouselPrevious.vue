<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { ChevronLeft } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import type { ButtonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useCarousel } from './useCarousel';

interface Props {
    variant?: ButtonVariants['variant'];
    size?: ButtonVariants['size'];
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'outline',
    size: 'icon-sm',
});

const { canScrollPrev, orientation, scrollPrev } = useCarousel();
</script>

<template>
    <Button
        type="button"
        data-slot="carousel-previous"
        :variant="variant"
        :size="size"
        :disabled="!canScrollPrev"
        :class="cn(
            'absolute z-10 rounded-full',
            orientation === 'horizontal'
                ? 'top-1/2 -left-12 -translate-y-1/2'
                : '-top-12 left-1/2 -translate-x-1/2 rotate-90',
            props.class,
        )"
        @click="scrollPrev"
    >
        <ChevronLeft class="size-4" />
        <span class="sr-only">Previous slide</span>
    </Button>
</template>
