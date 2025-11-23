<template>
    <header
        class="sticky top-0 z-50 w-full border-b"
        style="background-color: #000e29; border-color: #33415c;"
    >
        <div class="container mx-auto flex h-16 items-center justify-between px-4">
            <!-- App Icon / Logo -->
            <div class="flex items-center gap-3">
                <router-link to="/dashboard" class="flex items-center">
                    <AtlasIcon class="w-8 h-8" />
                </router-link>
                <span
                    class="text-xl font-bold"
                    style="color: #c3e0fe;"
                >
                    {{ appName }}
                </span>
            </div>

            <!-- User Menu -->
            <div class="flex items-center gap-4">
                <span
                    class="hidden md:block text-sm font-medium"
                    style="color: #d0d7e5;"
                >
                    {{ userName }}
                </span>
                
                <DropdownMenu>
                    <template #trigger>
                        <button
                            class="flex items-center gap-2 rounded-lg p-2 transition-colors"
                            style="color: #c3e0fe;"
                            aria-label="User menu"
                        >
                            <Menu class="w-5 h-5" />
                        </button>
                    </template>
                    
                    <DropdownMenuItem href="/profile">
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

function handleLogout(): void {
    emit('logout');
}
</script>

