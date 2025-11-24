<template>
    <div class="flex min-h-screen bg-prussian-blue-500">
        <!-- Side Menu -->
        <aside
            :class="[
                'fixed md:static z-40 h-screen bg-prussian-blue-500 border-r border-twilight-indigo-500 transition-all duration-300 ease-in-out',
                isMenuOpen ? 'w-64' : 'w-16',
                'flex flex-col'
            ]"
        >
            <!-- Toggle Button -->
            <div class="flex items-center justify-end h-16 px-4">
                <button
                    @click="toggleMenu"
                    class="p-2 rounded-lg transition-colors hover:bg-prussian-blue-400 text-smart-blue-900"
                    aria-label="Toggle menu"
                >
                    <ChevronLeft v-if="isMenuOpen" class="w-5 h-5" />
                    <ChevronRight v-else class="w-5 h-5" />
                </button>
            </div>

            <!-- Menu Items -->
            <nav class="flex-1 overflow-y-auto py-4">
                <div class="space-y-1 px-2">
                    <router-link
                        v-for="item in menuItems"
                        :key="item.name"
                        :to="item.path"
                        class="flex items-center gap-3 px-3 py-2 rounded-lg transition-colors text-twilight-indigo-900 hover:bg-prussian-blue-400 hover:text-smart-blue-900"
                        :class="{
                            'bg-prussian-blue-400 text-smart-blue-900': $route.name === item.name
                        }"
                    >
                        <component :is="item.icon" class="w-5 h-5 flex-shrink-0" />
                        <Transition name="fade">
                            <span v-if="isMenuOpen" class="font-medium whitespace-nowrap">
                                {{ item.label }}
                            </span>
                        </Transition>
                    </router-link>
                </div>
            </nav>

            <!-- User Menu at Bottom -->
            <div class="border-t border-twilight-indigo-500 p-4">
                <div class="relative">
                    <button
                        @click="toggleUserMenu"
                        class="w-full flex items-center gap-3 px-3 py-2 rounded-lg transition-colors text-twilight-indigo-900 hover:bg-prussian-blue-400 hover:text-smart-blue-900"
                    >
                        <User class="w-5 h-5 flex-shrink-0" />
                        <Transition name="fade">
                            <span v-if="isMenuOpen" class="font-medium whitespace-nowrap truncate flex-1 text-left">
                                {{ userName }}
                            </span>
                        </Transition>
                        <Transition name="fade">
                            <ChevronUp v-if="isMenuOpen && isUserMenuOpen" class="w-4 h-4 flex-shrink-0 ml-auto" />
                            <ChevronDown v-else-if="isMenuOpen" class="w-4 h-4 flex-shrink-0 ml-auto" />
                        </Transition>
                    </button>

                    <!-- User Dropdown Menu (expands upward) -->
                    <Transition
                        enter-active-class="transition ease-out duration-100"
                        enter-from-class="transform opacity-0 scale-95 translate-y-2"
                        enter-to-class="transform opacity-100 scale-100 translate-y-0"
                        leave-active-class="transition ease-in duration-75"
                        leave-from-class="transform opacity-100 scale-100 translate-y-0"
                        leave-to-class="transform opacity-0 scale-95 translate-y-2"
                    >
                        <div
                            v-if="isUserMenuOpen && isMenuOpen"
                            class="absolute bottom-full left-0 right-0 mb-2 rounded-lg shadow-lg bg-prussian-blue-500 border border-twilight-indigo-500 overflow-hidden"
                        >
                            <router-link
                                to="/profile"
                                @click="closeUserMenu"
                                class="flex items-center gap-3 px-3 py-2 transition-colors text-twilight-indigo-900 hover:bg-prussian-blue-400 hover:text-smart-blue-900"
                            >
                                <User class="w-4 h-4" />
                                <span class="font-medium">Profile</span>
                            </router-link>
                            <button
                                @click="handleUserLogout"
                                class="w-full flex items-center gap-3 px-3 py-2 transition-colors text-twilight-indigo-900 hover:bg-prussian-blue-400 hover:text-smart-blue-900"
                            >
                                <LogOut class="w-4 h-4" />
                                <span class="font-medium">Logout</span>
                            </button>
                        </div>
                    </Transition>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col min-w-0">
            <AppHeader :user-name="userName" :app-name="appName" @logout="handleLogout" />
            <main class="flex-1 bg-prussian-blue-400 rounded-lg shadow-xl md:p-8 md:p-12 overflow-auto">
                <router-view />
            </main>
        </div>

        <!-- Overlay for mobile -->
        <Transition
            enter-active-class="transition-opacity ease-linear duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity ease-linear duration-300"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isMenuOpen"
                @click="closeMenu"
                class="fixed inset-0 bg-black bg-opacity-50 z-30 md:hidden"
            />
        </Transition>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import {
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    ChevronDown,
    LayoutDashboard,
    Users,
    Folder,
    Settings,
    User,
    LogOut,
} from 'lucide-vue-next';
import AppHeader from './AppHeader.vue';

interface Props {
    userName: string;
    appName?: string;
}

const props = withDefaults(defineProps<Props>(), {
    appName: 'Atlas',
});

defineEmits<{
    logout: [];
}>();

const userName = props.userName;
const appName = props.appName;

const isMenuOpen = ref(true);
const isUserMenuOpen = ref(false);

const menuItems = [
    { name: 'dashboard', path: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { name: 'users', path: '/users', label: 'Users', icon: Users },
    { name: 'files', path: '/files', label: 'Files', icon: Folder },
    { name: 'settings', path: '/settings', label: 'Settings', icon: Settings },
];

function toggleMenu(): void {
    isMenuOpen.value = !isMenuOpen.value;
    if (!isMenuOpen.value) {
        isUserMenuOpen.value = false;
    }
}

function closeMenu(): void {
    isMenuOpen.value = false;
    isUserMenuOpen.value = false;
}

function toggleUserMenu(): void {
    if (!isMenuOpen.value) {
        isMenuOpen.value = true;
    }
    isUserMenuOpen.value = !isUserMenuOpen.value;
}

function closeUserMenu(): void {
    isUserMenuOpen.value = false;
}

function handleUserLogout(): void {
    closeUserMenu();
    handleLogout();
}

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

function handleClickOutside(event: MouseEvent): void {
    const target = event.target as HTMLElement;
    if (!target.closest('aside') && !target.closest('.user-menu-button')) {
        if (window.innerWidth < 768) {
            closeMenu();
        }
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>

