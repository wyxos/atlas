<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { ChevronRight } from 'lucide-vue-next';
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

const { canScrollNext, orientation, scrollNext } = useCarousel();
</script>

<template>
    <Button
        type="button"
        data-slot="carousel-next"
        :variant="variant"
        :size="size"
        :disabled="!canScrollNext"
        :class="cn(
            'absolute z-10 rounded-full',
            orientation === 'horizontal'
                ? 'top-1/2 -right-12 -translate-y-1/2'
                : '-bottom-12 left-1/2 -translate-x-1/2 rotate-90',
            props.class,
        )"
        @click="scrollNext"
    >
        <ChevronRight class="size-4" />
        <span class="sr-only">Next slide</span>
    </Button>
</template>
