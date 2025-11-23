<template>
    <component :is="iconComponent" :size="size" :class="iconClass" :stroke-width="strokeWidth" />
</template>

<script setup>
import { computed } from 'vue';
import * as LucideIcons from 'lucide-vue-next';

const props = defineProps({
    name: {
        type: String,
        required: true,
    },
    size: {
        type: [Number, String],
        default: 24,
    },
    class: {
        type: String,
        default: '',
    },
    strokeWidth: {
        type: [Number, String],
        default: 2,
    },
});

const iconComponent = computed(() => {
    const iconName = props.name.charAt(0).toUpperCase() + props.name.slice(1).replace(/-([a-z])/g, (g) => g[1].toUpperCase());
    return LucideIcons[iconName] || LucideIcons.X;
});

const iconClass = computed(() => props.class);
</script>

