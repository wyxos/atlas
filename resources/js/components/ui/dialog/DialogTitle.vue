<script setup lang="ts">
import { cn } from '@/lib/utils';
</script>

<template>
    <h2 :class="cn('text-lg font-semibold leading-none tracking-tight text-regal-navy-100', $attrs.class)">
        <slot />
    </h2>
</template>

