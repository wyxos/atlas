<script setup lang="ts">
// Wrapper component for consistent page padding
</script>

<template>
    <div class="h-[calc(100vh-4rem)] md:p-8 md:h-[calc(100vh-4rem)] md:flex md:flex-col">
        <div
            class="p-4 md:p-8 h-full md:flex-1 md:flex md:flex-col md:min-h-0 md:overflow-auto bg-prussian-blue-600 rounded-lg">
            <slot />
        </div>
    </div>
</template>
