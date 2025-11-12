<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const environment = computed(() => page.props.environment as string);
const isProduction = computed(() => environment.value === 'production');
</script>

<template>
    <div
        v-if="!isProduction"
        class="fixed top-0 left-1/2 z-[9999] -translate-x-1/2 rounded-b-md bg-destructive px-4 py-1.5 text-xs font-semibold text-destructive-foreground shadow-lg"
    >
        {{ environment.toUpperCase() }}
    </div>
</template>

