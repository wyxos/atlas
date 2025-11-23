<template>
    <div class="min-h-screen" style="background-color: #001233;">
        <AppHeader :user-name="userName" :app-name="appName" @logout="handleLogout" />
        <main class="container mx-auto px-4 py-8">
            <router-view />
        </main>
    </div>
</template>

<script setup lang="ts">
import AppHeader from './AppHeader.vue';

interface Props {
    userName: string;
    appName?: string;
}

const props = withDefaults(defineProps<Props>(), {
    appName: 'Atlas',
});

const userName = props.userName;
const appName = props.appName;

function handleLogout(): void {
    // Get CSRF token from meta tag or axios defaults
    let csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    
    // If not found, try to get it from axios defaults (set in bootstrap.ts)
    if (!csrfToken && window.axios?.defaults?.headers?.common?.['X-CSRF-TOKEN']) {
        csrfToken = window.axios.defaults.headers.common['X-CSRF-TOKEN'] as string;
    }
    
    if (!csrfToken) {
        console.error('CSRF token not found');
        // Still try to submit - Laravel will handle CSRF validation
    }

    // Always use form submission for logout to ensure proper session handling
    // This ensures cookies and session are properly cleared
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/logout';
    form.style.display = 'none';
    
    if (csrfToken) {
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value = csrfToken;
        form.appendChild(csrfInput);
    }
    
    document.body.appendChild(form);
    form.submit();
    // Form submission will cause a full page reload, which ensures logout is complete
}
</script>

