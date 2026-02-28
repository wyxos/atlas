<script setup lang="ts">
import Badge from '@/components/ui/Badge.vue';

const extensionVersion = __ATLAS_EXTENSION_VERSION__;
</script>

<template>
    <main class="min-h-screen p-4 app-gradient">
        <section class="rounded-lg border border-smart-blue-500/30 bg-prussian-blue-700/60 p-4 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-base font-semibold text-regal-navy-100">Atlas Browser Extension</h1>
                <Badge variant="active">Ready</Badge>
            </div>

            <p class="text-sm text-twilight-indigo-200">
                Version
                <span class="font-medium text-smart-blue-200">{{ extensionVersion }}</span>
            </p>
        </section>
    </main>
</template>
