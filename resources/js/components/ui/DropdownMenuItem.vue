<script setup lang="ts">
import { ref } from 'vue';

interface Props {
    href?: string;
}

const props = withDefaults(defineProps<Props>(), {
    href: '#',
});

const isHovered = ref(false);

const emit = defineEmits<{
    click: [event: MouseEvent];
}>();

function handleClick(event: MouseEvent): void {
    // Always prevent default for buttons or # links
    if (!props.href || props.href === '#') {
        event.preventDefault();
    }
    emit('click', event);
}
</script>

<template>
    <component
        :is="href && href !== '#' ? 'a' : 'button'"
        :href="href && href !== '#' ? href : undefined"
        @click="handleClick"
        class="block w-full text-left px-4 py-2 text-sm transition-colors cursor-pointer text-twilight-indigo-100"
        :class="{
            'bg-smart-blue-700 bg-opacity-20': isHovered,
        }"
        @mouseenter="isHovered = true"
        @mouseleave="isHovered = false"
    >
        <slot />
    </component>
</template>
