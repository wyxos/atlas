<script setup lang="ts">
</script>

<template>
    <div class="flex flex-1 flex-col gap-4 rounded-xl md:p-4 overflow-hidden">
        <div class="relative flex-1 flex flex-col rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border overflow-hidden">
            <slot></slot>
        </div>
    </div>
</template>
