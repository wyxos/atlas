<script setup lang="ts">
import { SidebarProvider } from '@/components/ui/sidebar';
import { usePage } from '@inertiajs/vue3';
import EnvironmentBadge from '@/components/EnvironmentBadge.vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

interface Props {
    variant?: 'header' | 'sidebar';
    class?: HTMLAttributes['class'];
}

const props = defineProps<Props>();

const isOpen = usePage().props.sidebarOpen;
</script>

<template>
    <EnvironmentBadge />
    <div v-if="props.variant === 'header'" :class="cn('flex min-h-screen w-full flex-col', props.class)">
        <slot />
    </div>
    <SidebarProvider v-else :default-open="isOpen" :class="props.class">
        <slot />
    </SidebarProvider>
</template>
