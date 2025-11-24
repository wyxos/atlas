<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    href?: string;
    variant?: 'default' | 'no-underline' | 'sapphire' | 'muted';
}

const props = withDefaults(defineProps<Props>(), {
    href: '#',
    variant: 'default',
});

const linkClasses = computed(() => {
    const baseClasses = 'font-medium transition-colors';
    const variantClasses = {
        default: 'underline text-smart-blue-700 hover:text-smart-blue-600',
        'no-underline': 'text-smart-blue-700 hover:text-smart-blue-600',
        sapphire: 'text-sapphire-700 hover:text-sapphire-600',
        muted: 'text-twilight-indigo-700 hover:text-slate-grey-700',
    };
    return `${baseClasses} ${variantClasses[props.variant]}`;
});
</script>

<template>
    <a :href="href" :class="linkClasses">
        <slot />
    </a>
</template>

