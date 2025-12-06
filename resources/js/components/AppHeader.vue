<script setup lang="ts">
import { Menu } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

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
    <header class="sticky top-0 z-50 w-full md:hidden">
        <div class="relative flex h-16 items-center justify-center px-4">
            <!-- Mobile Menu Toggle (Only visible on mobile) -->
            <Button
                variant="ghost"
                size="icon"
                @click="handleToggleMenu"
                class="absolute left-4 text-smart-blue-100 mobile-menu-toggle"
                aria-label="Toggle menu"
            >
                <Menu :size="24" />
            </Button>
        </div>
    </header>
</template>
