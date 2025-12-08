<script setup lang="ts">
import { useAttrs } from 'vue';
import { PopoverContent } from '../popover';

defineOptions({
    inheritAttrs: false,
});

const attrs = useAttrs();
</script>

<template>
    <PopoverContent
        class="p-1"
        data-test="select-content"
        v-bind="attrs"
    >
        <slot />
    </PopoverContent>
</template>

