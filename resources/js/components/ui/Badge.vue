<script setup lang="ts">
import { computed } from 'vue';
import { CheckCircle2 } from 'lucide-vue-next';

interface Props {
    variant?: 'verified' | 'active' | 'inactive' | 'error' | 'pending';
    iconOnly?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'active',
    iconOnly: false,
});

const badgeClasses = computed(() => {
    const baseClasses = props.iconOnly
        ? 'inline-flex items-center justify-center p-1.5 rounded-sm'
        : 'px-3 py-1 rounded-sm text-xs font-medium';

    const variantClasses = {
        verified: props.iconOnly
            ? 'bg-success-700 border border-success-500 text-white'
            : 'bg-smart-blue-700 border border-smart-blue-500 text-white',
        active: 'bg-smart-blue-700 border border-smart-blue-500 text-white',
        inactive: 'bg-twilight-indigo-500 border border-blue-slate-500 text-white',
        error: 'bg-danger-700 border border-danger-400 text-white',
        pending: 'bg-sapphire-700 border border-sapphire-500 text-white',
    };

    return `${baseClasses} ${variantClasses[props.variant]}`;
});
</script>

<template>
    <span :class="badgeClasses" :title="iconOnly && variant === 'verified' ? 'Verified' : undefined">
        <CheckCircle2 v-if="iconOnly && variant === 'verified'" :size="16" />
        <slot v-else />
    </span>
</template>

