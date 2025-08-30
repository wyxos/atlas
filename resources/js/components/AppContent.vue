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
    <main v-else :class="className" class="flex flex-col">
        <slot />
        <GlobalAudioProvider />
    </main>
</template>
