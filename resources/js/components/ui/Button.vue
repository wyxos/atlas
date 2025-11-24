<script setup lang="ts">
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
    'inline-flex items-center justify-center rounded-lg font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50 disabled:pointer-events-none',
    {
        variants: {
            variant: {
                default: 'text-white shadow-lg',
                outline: 'border-2 bg-transparent',
                ghost: 'bg-transparent hover:bg-opacity-10',
            },
            size: {
                default: 'px-6 py-3',
                sm: 'px-3 py-1.5 text-sm',
                lg: 'px-8 py-4 text-lg',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    }
);

type ButtonVariants = VariantProps<typeof buttonVariants>;

interface Props {
    variant?: ButtonVariants['variant'];
    size?: ButtonVariants['size'];
}

withDefaults(defineProps<Props>(), {
    variant: 'default',
    size: 'default',
});
</script>

<template>
    <button
        :class="cn(buttonVariants({ variant, size }), $attrs.class)"
        v-bind="$attrs"
    >
        <slot />
    </button>
</template>

<style scoped>
/* Smart Blue variant styles */
button.variant-default {
    background-color: #0466c8;
}

button.variant-default:hover {
    background-color: #0f85fa;
}

button.variant-outline {
    border-color: #0f85fa;
    color: #0f85fa;
}

button.variant-outline:hover {
    background-color: #023d78;
}

button.variant-ghost {
    color: #4ba3fb;
}

button.variant-ghost:hover {
    background-color: #023d78;
}
</style>
