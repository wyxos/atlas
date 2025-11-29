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
                ghost: 'bg-transparent border-2 border-transparent',
            },
            size: {
                default: 'px-6 py-3',
                sm: 'px-3 py-1.5 text-sm',
                lg: 'px-8 py-4 text-lg',
            },
            color: {
                default: '',
                danger: '',
                success: '',
                sapphire: '',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
            color: 'default',
        },
    }
);

type ButtonVariants = VariantProps<typeof buttonVariants>;

interface Props {
    variant?: ButtonVariants['variant'];
    size?: ButtonVariants['size'];
    color?: ButtonVariants['color'];
    loading?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'default',
    size: 'default',
    color: 'default',
    loading: false,
});

const attrs = useAttrs();
const isDisabled = computed(() => {
    return props.loading || (attrs.disabled as boolean | undefined) === true;
});

function getColorClasses(): string {
    const { variant, color } = props;

    if (variant === 'default') {
        switch (color) {
            case 'danger':
                return 'bg-danger-400 hover:bg-danger-700';
            case 'success':
                return 'bg-success-500 hover:bg-success-400';
            case 'sapphire':
                return 'bg-sapphire-500 hover:bg-sapphire-400';
            default:
                return 'bg-smart-blue-500 hover:bg-smart-blue-400';
        }
    }

    if (variant === 'outline') {
        switch (color) {
            case 'danger':
                return 'border-danger-400 text-danger-400 hover:bg-danger-700 hover:border-danger-400 hover:text-danger-100';
            case 'success':
                return 'border-success-400 text-success-400 hover:bg-success-700 hover:border-success-400 hover:text-success-100';
            case 'sapphire':
                return 'border-sapphire-600 text-sapphire-600 hover:bg-sapphire-300';
            default:
                return 'border-smart-blue-400 text-smart-blue-400 hover:bg-smart-blue-700 hover:border-smart-blue-400 hover:text-smart-blue-100';
        }
    }

    if (variant === 'ghost') {
        switch (color) {
            case 'danger':
                return 'border-danger-500/30 text-danger-300 hover:bg-danger-700/20 hover:border-danger-500/50';
            case 'success':
                return 'border-success-500/30 text-success-300 hover:bg-success-700/20 hover:border-success-500/50';
            case 'sapphire':
                return 'border-sapphire-500/30 text-sapphire-300 hover:bg-sapphire-700/20 hover:border-sapphire-500/50';
            default:
                return 'border-smart-blue-500/30 text-smart-blue-300 hover:bg-smart-blue-700/20 hover:border-smart-blue-500/50';
        }
    }

    return '';
}
</script>

<template>
    <button :class="cn(buttonVariants({ variant, size, color }), getColorClasses(), attrs.class as string)"
        v-bind="$attrs" :disabled="isDisabled">
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
