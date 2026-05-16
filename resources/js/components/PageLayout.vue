<script setup lang="ts">
withDefaults(defineProps<{
    flush?: boolean;
}>(), {
    flush: false,
});
</script>

<template>
    <div
        data-test="page-layout"
        :class="[
            'h-full min-h-0 md:flex md:flex-col',
        ]"
    >
        <div
            data-test="page-layout-content"
            :class="[
                'h-full min-h-0 md:flex md:flex-1 md:flex-col md:overflow-auto',
                flush ? '' : 'bg-prussian-blue-600',
            ]"
        >
            <slot />
        </div>
    </div>
</template>
