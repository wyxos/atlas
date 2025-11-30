<script setup lang="ts">
import PageLayout from './PageLayout.vue';
import GuidelinesNav from './GuidelinesNav.vue';
</script>

<template>
    <PageLayout>
        <div class="flex gap-8 w-full">
            <GuidelinesNav />
            <main class="flex-1 min-w-0">
                <slot />
            </main>
        </div>
    </PageLayout>
    </template>


