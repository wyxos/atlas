<script setup lang="ts">
withDefaults(defineProps<{
    flush?: boolean;
}>(), {
    flush: false,
});
</script>

<template>
    <div
        :class="[
            'h-[calc(100vh-4rem)] md:h-[calc(100vh-4rem)] md:flex md:flex-col',
            flush ? '' : 'md:p-8',
        ]"
    >
        <div
            :class="[
                'h-full md:flex-1 md:flex md:flex-col md:min-h-0 md:overflow-auto',
                flush ? '' : 'p-4 md:p-8 bg-prussian-blue-600 rounded-lg',
            ]"
        >
            <slot />
        </div>
    </div>
</template>
