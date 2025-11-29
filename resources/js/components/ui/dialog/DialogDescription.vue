<script setup lang="ts">
import { cn } from '@/lib/utils';
</script>

<template>
    <p :class="cn('text-sm text-blue-slate-300', $attrs.class)">
        <slot />
    </p>
</template>

