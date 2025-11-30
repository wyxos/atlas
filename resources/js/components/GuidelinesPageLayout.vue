<script setup lang="ts">
import { useRoute } from 'vue-router';
import PageLayout from './PageLayout.vue';
import GuidelinesNav from './GuidelinesNav.vue';

const route = useRoute();
</script>

<template>
    <PageLayout>
        <div class="flex gap-8 w-full items-start">
            <GuidelinesNav />
            <main class="flex-1 min-w-0">
                <router-view :key="route.fullPath" />
            </main>
        </div>
    </PageLayout>
    </template>


