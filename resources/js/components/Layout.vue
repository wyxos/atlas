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

async function handleLogout(): Promise<void> {
    try {
        // Use axios if available, otherwise fallback to form submission
        if (window.axios) {
            await window.axios.post('/logout');
            window.location.href = '/';
        } else {
            // Fallback to form submission
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (!csrfToken) {
                console.error('CSRF token not found');
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/logout';
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    } catch (error) {
        console.error('Logout error:', error);
        // Fallback to form submission on error
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            console.error('CSRF token not found');
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/logout';
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value = csrfToken;
        form.appendChild(csrfInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

