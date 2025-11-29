<script setup lang="ts">
import { Menu } from 'lucide-vue-next';
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
    'toggle-menu': [];
}>();

function handleToggleMenu(): void {
    emit('toggle-menu');
}
</script>

<template>
    <header class="sticky top-0 z-50 w-full bg-prussian-blue-600">
        <div class="relative flex h-16 items-center justify-center lg:justify-between px-4 lg:px-8">
            <!-- Mobile/Tablet Menu Toggle (Absolute on mobile/tablet) -->
            <button
                @click="handleToggleMenu"
                class="absolute left-4 lg:static lg:hidden p-2 rounded-lg transition-colors hover:bg-prussian-blue-500 text-smart-blue-100 mobile-menu-toggle"
                aria-label="Toggle menu"
            >
                <Menu :size="24" />
            </button>

            <!-- App Icon / Logo -->
            <div class="flex items-center gap-3">
                <router-link to="/dashboard" class="flex items-center">
                    <AtlasIcon class="w-10 h-10" />
                </router-link>
                <span class="text-xl font-bold text-smart-blue-100">
                    {{ appName }}
                </span>
            </div>

            <!-- User Menu (Hidden/Removed as requested, but keeping structure if needed later, or just removing) -->
            <!-- User requested: "The menu on the right is now obsolete." -->
        </div>
    </header>
</template>
