<script setup lang="ts">
import { computed, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';
import DashboardLayout from './layouts/DashboardLayout.vue';
import PublicLayout from './layouts/PublicLayout.vue';

const route = useRoute();
const layout = shallowRef(DashboardLayout);

watch(
    () => route.meta?.layout,
    (newLayout) => {
        if (newLayout === 'PublicLayout') {
            layout.value = PublicLayout;
        } else {
            layout.value = DashboardLayout;
        }
    },
    { immediate: true }
);

// Get user data from meta tag or global variable
const userName = computed(() => {
    const metaTag = document.querySelector('meta[name="user-name"]');
    return metaTag?.getAttribute('content') || 'User';
});

const appName = computed(() => {
    const metaTag = document.querySelector('meta[name="app-name"]');
    return metaTag?.getAttribute('content') || 'Atlas';
});

function handleLogout() {
    // Re-implement logout logic or emit to layout if needed, 
    // but since the layout handles the UI, we might just need to pass it through
    // or let the layout handle it directly.
    // For now, let's keep the prop passing if the layout emits it.
}
</script>

<template>
    <div id="app">
        <component :is="layout" :user-name="userName" :app-name="appName" @logout="handleLogout">
            <router-view />
        </component>
    </div>
</template>

<style>
/* Global styles if needed */
</style>
