<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue';
import {
    LayoutDashboard,
    Users,
    Folder,
    Settings,
    User,
    LogOut,
    Search,
    Music,
    Video,
    Image,
    Download,
    Activity,
} from 'lucide-vue-next';
import AppHeader from '../components/AppHeader.vue';
import AtlasIcon from '../components/AtlasIcon.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// Get user data from meta tag or global variable
const props = defineProps<{
    appVersion?: string;
}>();

const userName = computed(() => {
    const metaTag = document.querySelector('meta[name="user-name"]');
    return metaTag?.getAttribute('content') || 'User';
});

const userInitials = computed(() => {
    return userName.value
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((name) => name[0]?.toUpperCase())
        .join('') || 'U';
});

const appName = computed(() => {
    const metaTag = document.querySelector('meta[name="app-name"]');
    return metaTag?.getAttribute('content') || 'Atlas';
});

const appVersion = computed(() => props.appVersion || 'dev');
const isAdmin = computed(() => {
    const metaTag = document.querySelector('meta[name="user-is-admin"]');

    return metaTag?.getAttribute('content') === '1';
});

// On mobile/tablet, menu starts closed; on desktop, it starts open
const isMenuOpen = ref(window.innerWidth >= 1024);

const menuItems = [
    { name: 'dashboard', path: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { name: 'browse', path: '/browse', label: 'Browse', icon: Search },
    { name: 'audio', path: '/audio', label: 'Audio', icon: Music },
    { name: 'videos', path: '/videos', label: 'Videos', icon: Video },
    { name: 'photos', path: '/photos', label: 'Photos', icon: Image },
    { name: 'files', path: '/files', label: 'Files', icon: Folder },
    { name: 'downloads', path: '/downloads-queue', label: 'Downloads', icon: Download },
    { name: 'users', path: '/users', label: 'Users', icon: Users },
    { name: 'settings', path: '/settings', label: 'Settings', icon: Settings },
];

function toggleMenu(): void {
    isMenuOpen.value = !isMenuOpen.value;
}

function handleResize(): void {
    const isDesktopNow = window.innerWidth >= 1024;
    // On desktop, menu should be open; on mobile/tablet, it should be closed
    if (isDesktopNow) {
        if (!isMenuOpen.value) {
            isMenuOpen.value = true;
        }
    } else {
        if (isMenuOpen.value) {
            isMenuOpen.value = false;
        }
    }
}

function closeMenu(): void {
    isMenuOpen.value = false;
}

function handleMenuItemClick(): void {
    // Close menu on mobile/tablet when clicking a menu item
    if (window.innerWidth < 1024) {
        closeMenu();
    }
}

function handleUserLogout(): void {
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
    if (!target.closest('aside') && !target.closest('.user-menu-button') && !target.closest('.mobile-menu-toggle')) {
        if (window.innerWidth < 1024) {
            closeMenu();
        }
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    window.addEventListener('resize', handleResize);
    handleResize(); // Set initial state based on screen size
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    window.removeEventListener('resize', handleResize);
});
</script>

<template>
    <div class="flex min-h-screen bg-gradient-to-b from-prussian-blue-900 via-prussian-blue-800 to-prussian-blue-700">
        <!-- Side Menu -->
        <aside :class="[
            'absolute lg:static z-40 h-screen bg-prussian-blue-800 border-r border-twilight-indigo-500 transition-all duration-300 ease-in-out',
            'flex flex-col',
            isMenuOpen ? 'w-24 translate-x-0' : 'w-24 -translate-x-full lg:translate-x-0'
        ]">
            <!-- Atlas Icon / Logo -->
            <div class="flex h-20 flex-col items-center justify-center gap-1 px-2">
                <AtlasIcon class="h-10 w-10" />
                <span class="text-[10px] font-medium text-twilight-indigo-300 tracking-wide">v{{ appVersion }}</span>
            </div>

            <!-- Menu Items -->
            <nav class="flex-1 overflow-y-auto py-4">
                <div class="space-y-2 px-2">
                    <router-link v-for="item in menuItems" :key="item.name" :to="item.path" @click="handleMenuItemClick"
                        active-class="!bg-smart-blue-600 !text-white"
                        exact-active-class="!bg-smart-blue-600 !text-white"
                        class="flex min-h-16 flex-col items-center justify-center gap-1 rounded-lg bg-transparent px-1.5 py-2 text-center text-twilight-indigo-100 transition-colors hover:bg-smart-blue-700/50 hover:text-white">
                        <component :is="item.icon" class="size-8 flex-shrink-0" />
                        <span class="w-full text-[11px] font-medium leading-tight">
                            {{ item.label }}
                        </span>
                    </router-link>
                    <a
                        v-if="isAdmin"
                        href="/horizon"
                        @click="handleMenuItemClick"
                        class="flex min-h-16 flex-col items-center justify-center gap-1 rounded-lg bg-transparent px-1.5 py-2 text-center text-twilight-indigo-100 transition-colors hover:bg-smart-blue-700/50 hover:text-white"
                    >
                        <Activity class="size-8 flex-shrink-0" />
                        <span class="w-full text-[11px] font-medium leading-tight">
                            Horizon
                        </span>
                    </a>
                </div>
            </nav>

            <!-- User Menu at Bottom -->
            <div class="border-t border-twilight-indigo-500">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            :aria-label="`Open user menu for ${userName}`"
                            class="h-16 w-full rounded-none border-0 p-0 text-twilight-indigo-100 hover:text-smart-blue-100 [&>span]:justify-center"
                        >
                            <span class="text-xs font-semibold leading-none tracking-wide text-smart-blue-100">
                                {{ userInitials }}
                            </span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="top" align="start"
                        class="bg-prussian-blue-600 border-twilight-indigo-500 text-twilight-indigo-100 w-56">
                        <DropdownMenuLabel class="text-smart-blue-100">{{ userName }}</DropdownMenuLabel>
                        <DropdownMenuSeparator class="bg-twilight-indigo-500" />
                        <DropdownMenuItem as-child class="focus:bg-smart-blue-700/50 focus:text-white">
                            <router-link to="/profile" class="flex items-center gap-2 w-full">
                                <User class="w-4 h-4" />
                                <span>Profile</span>
                            </router-link>
                        </DropdownMenuItem>
                        <DropdownMenuItem @click="handleUserLogout"
                            class="focus:bg-smart-blue-700/50 focus:text-white cursor-pointer">
                            <LogOut class="w-4 h-4" />
                            <span>Logout</span>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col min-w-0 lg:max-h-screen">
            <AppHeader :user-name="userName" :app-name="appName" @logout="handleLogout" @toggle-menu="toggleMenu" />
            <main class="flex-1 overflow-auto lg:overflow-y-auto">
                <slot></slot>
            </main>
        </div>

        <!-- Overlay for mobile -->
        <Transition enter-active-class="transition-opacity ease-linear duration-300" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition-opacity ease-linear duration-300"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="isMenuOpen" @click="closeMenu"
                class="fixed inset-0 bg-black bg-opacity-50 z-30 lg:hidden transition-opacity duration-300" />
        </Transition>
    </div>
</template>

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
