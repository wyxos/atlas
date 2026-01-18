<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

const items = Array.from({ length: 1000 }, (_, index) => ({
    id: index + 1,
    name: `Demo Item ${index + 1}`,
    url: null as string | null,
    status: null as string | null,
}));
</script>

<template>
    <div class="mb-8 rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700">
        <div class="border-b border-twilight-indigo-500/40 px-4 py-2 text-xs uppercase tracking-wide text-blue-slate-300">
            Demo List
        </div>
        <div class="max-h-64 overflow-auto px-4 py-2">
            <div
                v-for="item in items"
                :key="item.id"
                class="flex items-center gap-3 border-b border-twilight-indigo-500/20 py-1 text-sm text-twilight-indigo-100 last:border-b-0"
            >
                <span class="text-blue-slate-300">#{{ item.id }}</span>
                <span class="min-w-0 flex-1 truncate">{{ item.name }}</span>
                <Skeleton
                    v-if="!item.url"
                    class="h-3 w-32 bg-prussian-blue-500/60"
                />
                <span v-else class="text-xs text-blue-slate-300">{{ item.url }}</span>
                <Skeleton
                    v-if="!item.status"
                    class="h-3 w-16 bg-prussian-blue-500/60"
                />
                <span v-else class="text-xs text-blue-slate-300">{{ item.status }}</span>
            </div>
        </div>
    </div>
</template>
