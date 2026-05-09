<script setup lang="ts">
import { formatDashboardCount } from '@/utils/dashboard';

interface Props {
    value: number;
    denominator?: number;
    color: string;
}

defineProps<Props>();
</script>

<template>
    <span class="tabular-nums">
        <span :style="{ color }">{{ formatDashboardCount(value) }}</span>
        <span v-if="denominator !== undefined" class="text-regal-navy-100">
            / {{ formatDashboardCount(denominator) }}
        </span>
    </span>
</template>
