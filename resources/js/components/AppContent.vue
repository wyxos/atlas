<script setup lang="ts">
import { SidebarInset } from '@/components/ui/sidebar';
import { computed } from 'vue';
import GlobalAudioProvider from '@/components/GlobalAudioProvider.vue';

interface Props {
    variant?: 'header' | 'sidebar';
    class?: string;
}

const props = defineProps<Props>();
const className = computed(() => props.class);
</script>

<template>
    <SidebarInset v-if="props.variant === 'sidebar'" :class="className">
        <slot />
        <GlobalAudioProvider />
    </SidebarInset>
    <main v-else class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl relative" :class="className">
        <slot />
        <GlobalAudioProvider />
    </main>
</template>
