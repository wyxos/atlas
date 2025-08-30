<script lang="ts" setup>
import GlobalAudioProvider from '@/components/GlobalAudioProvider.vue';
import { SidebarInset } from '@/components/ui/sidebar';
import { computed } from 'vue';

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
    <main v-else :class="className" class="relative mx-auto flex h-screen w-full max-w-7xl flex-1 flex-col gap-4 overflow-hidden rounded-xl">
        <slot />
        <GlobalAudioProvider />
    </main>
</template>
