<script setup lang="ts">
const props = defineProps<{
    appVersion?: string;
}>();

const appVersion = props.appVersion || 'dev';
</script>

<template>
    <div class="min-h-screen bg-prussian-blue-600 text-dull-white-500">
        <header class="bg-prussian-blue-700 border-b border-twilight-indigo-500">
            <div class="container mx-auto px-4 h-16 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded bg-smart-blue-500 flex items-center justify-center text-white font-bold">
                        A
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="font-bold text-xl text-white">Atlas</span>
                        <span class="text-xs font-medium text-twilight-indigo-300">v{{ appVersion }}</span>
                    </div>
                </div>
                
                <a href="/" class="text-smart-blue-400 hover:text-smart-blue-300 transition-colors font-medium">
                    Back to Home
                </a>
            </div>
        </header>

        <main>
            <slot></slot>
        </main>
    </div>
</template>
