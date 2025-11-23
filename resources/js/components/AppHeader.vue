<template>
    <header class="sticky top-0 z-50 w-full border-b bg-prussian-blue-400 border-twilight-indigo-500">
        <div class="container mx-auto flex h-16 items-center justify-between px-4">
            <!-- App Icon / Logo -->
            <div class="flex items-center gap-3">
                <router-link to="/dashboard" class="flex items-center">
                    <AtlasIcon class="w-14 h-14" />
                </router-link>
                <span class="text-xl font-bold text-smart-blue-900">
                    {{ appName }}
                </span>
            </div>

            <!-- User Menu -->
            <div class="flex items-center gap-4">
                <span class="hidden md:block text-sm font-medium text-twilight-indigo-900">
                    {{ userName }}
                </span>
                
                <DropdownMenu>
                    <template #trigger>
                        <button
                            class="flex items-center gap-2 rounded-lg p-2 transition-colors cursor-pointer text-smart-blue-900"
                            aria-label="User menu"
                        >
                            <Menu class="w-5 h-5" />
                        </button>
                    </template>
                    
                    <DropdownMenuItem @click="handleProfileClick">
                        <div class="flex items-center gap-2">
                            <User class="w-4 h-4" />
                            <span>Profile</span>
                        </div>
                    </DropdownMenuItem>
                    
                    <DropdownMenuItem @click="handleLogout">
                        <div class="flex items-center gap-2">
                            <LogOut class="w-4 h-4" />
                            <span>Sign Out</span>
                        </div>
                    </DropdownMenuItem>
                </DropdownMenu>
            </div>
        </div>
    </header>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router';
import { Menu, User, LogOut } from 'lucide-vue-next';
import DropdownMenu from './ui/DropdownMenu.vue';
import DropdownMenuItem from './ui/DropdownMenuItem.vue';
import AtlasIcon from './AtlasIcon.vue';

interface Props {
    userName: string;
    appName?: string;
}

withDefaults(defineProps<Props>(), {
    appName: 'Atlas',
});

const emit = defineEmits<{
    logout: [];
}>();

const router = useRouter();

function handleProfileClick(): void {
    router.push('/profile');
}

function handleLogout(): void {
    emit('logout');
}
</script>

