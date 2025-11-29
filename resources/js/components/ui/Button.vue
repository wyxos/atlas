<script setup lang="ts">
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';
import { Loader2 } from 'lucide-vue-next';
import { computed, useAttrs } from 'vue';

const buttonVariants = cva(
    'relative inline-flex items-center justify-center rounded-lg font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50 disabled:pointer-events-none box-border',
    {
        variants: {
            variant: {
                default: 'text-white shadow-lg border-2 border-transparent',
                outline: 'border-2 bg-transparent',
                ghost: 'bg-transparent hover:bg-opacity-10 border-2 border-transparent',
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
    loading?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'default',
    size: 'default',
    loading: false,
});

const attrs = useAttrs();
const isDisabled = computed(() => {
    return props.loading || (attrs.disabled as boolean | undefined) === true;
});
</script>

<template>
    <button :class="cn(buttonVariants({ variant, size }), attrs.class as string)" v-bind="$attrs"
        :disabled="isDisabled">
        <span class="invisible">
            <slot />
        </span>
        <span class="absolute inset-0 flex items-center justify-center">
            <Transition name="fade" mode="out-in">
                <Loader2 v-if="loading" :size="size === 'sm' ? 16 : size === 'lg' ? 24 : 20" class="animate-spin" />
                <span v-else>
                    <slot />
                </span>
            </Transition>
        </span>
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

/* Fade transition for spinner/content */
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
