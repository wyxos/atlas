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
    // Create a form and submit it for logout
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/logout';
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrfToken) {
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value = csrfToken;
        form.appendChild(csrfInput);
    }
    
    document.body.appendChild(form);
    form.submit();
}
</script>

