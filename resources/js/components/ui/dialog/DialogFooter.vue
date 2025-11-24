<script setup lang="ts">
import { cn } from '@/lib/utils';
</script>

<template>
    <div :class="cn('flex flex-col-reverse sm:flex-row sm:justify-end gap-2 sm:gap-2 mt-6', $attrs.class)">
        <slot />
    </div>
</template>

