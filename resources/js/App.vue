<template>
    <div id="app">
        <Layout :user-name="userName" :app-name="appName" @logout="handleLogout" />
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import Layout from './components/Layout.vue';

// Get user data from meta tag or global variable
const userName = computed(() => {
    const metaTag = document.querySelector('meta[name="user-name"]');
    return metaTag?.getAttribute('content') || 'User';
});

const appName = computed(() => {
    const metaTag = document.querySelector('meta[name="app-name"]');
    return metaTag?.getAttribute('content') || 'Atlas';
});

function handleLogout(): void {
    // Logout is handled in Layout component
}
</script>

<style>
/* Global styles if needed */
</style>

