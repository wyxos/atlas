<script setup lang="ts">
// Wrapper component for consistent page padding
</script>

<template>
    <div class="min-h-[calc(100vh-4rem)] md:p-8 md:h-[calc(100vh-4rem)] md:flex md:flex-col">
        <div class="bg-prussian-blue-300 p-4 md:p-8 rounded min-h-[calc(100vh-4rem)] md:flex-1 md:flex md:flex-col md:min-h-0 md:overflow-auto">
            <slot />
        </div>
    </div>
</template>
