<template>
    <a
        :href="href"
        @click="handleClick"
        class="block px-4 py-2 text-sm transition-colors"
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
    </a>
</template>

<script setup lang="ts">
import { ref } from 'vue';

interface Props {
    href?: string;
}

withDefaults(defineProps<Props>(), {
    href: '#',
});

const isHovered = ref(false);

const emit = defineEmits<{
    click: [event: MouseEvent];
}>();

function handleClick(event: MouseEvent): void {
    emit('click', event);
}
</script>

