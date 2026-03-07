<script setup lang="ts">
import type { ContainerMetricItem } from '@/types/dashboard';
import {
    buildDashboardContainerTabPayload,
    formatDashboardContainerLabel,
    formatDashboardCount,
} from '@/utils/dashboard';

interface Props {
    title: string;
    items: ContainerMetricItem[];
}

defineProps<Props>();

const emit = defineEmits<{
    'open-in-app': [item: ContainerMetricItem];
}>();

function canOpenInApp(item: ContainerMetricItem): boolean {
    return buildDashboardContainerTabPayload(item) !== null;
}
</script>

<template>
    <div class="space-y-3">
        <div class="text-sm font-semibold text-regal-navy-100">{{ title }}</div>

        <div class="space-y-2">
            <div
                v-for="item in items"
                :key="`${title}-${item.id}`"
                class="flex items-center justify-between gap-3 text-xs"
            >
                <div class="min-w-0 flex-1">
                    <a
                        v-if="item.referrer"
                        class="block truncate text-twilight-indigo-100 transition-colors hover:text-white"
                        :href="item.referrer"
                        :title="formatDashboardContainerLabel(item)"
                        target="_blank"
                        rel="noreferrer"
                    >
                        {{ formatDashboardContainerLabel(item) }}
                    </a>
                    <span
                        v-else
                        class="block truncate text-twilight-indigo-200"
                        :title="formatDashboardContainerLabel(item)"
                    >
                        {{ formatDashboardContainerLabel(item) }}
                    </span>
                    <button
                        v-if="canOpenInApp(item)"
                        type="button"
                        class="mt-1 inline-flex items-center text-[11px] font-semibold text-twilight-indigo-300 transition-colors hover:text-twilight-indigo-100"
                        @click="emit('open-in-app', item)"
                    >
                        Open in app
                    </button>
                </div>
                <span class="font-semibold text-regal-navy-100">{{ formatDashboardCount(item.files_count) }}</span>
            </div>

            <div v-if="items.length === 0" class="text-xs text-twilight-indigo-300">
                No containers yet.
            </div>
        </div>
    </div>
</template>
