<template>
    <component
        :is="href && href !== '#' ? 'a' : 'button'"
        :href="href && href !== '#' ? href : undefined"
        @click="handleClick"
        class="block w-full text-left px-4 py-2 text-sm transition-colors cursor-pointer"
        style="color: #d0d7e5;"
        :class="{
            'hover:bg-opacity-10': true,
        }"
        :style="{
            backgroundColor: isHovered ? 'rgba(4, 102, 200, 0.1)' : 'transparent',
        }"
        @mouseenter="isHovered = true"
        @mouseleave="isHovered = false"
    >
        <slot />
    </component>
</template>

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

