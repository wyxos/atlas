<script setup lang="ts">
import type { Component } from 'vue';
import {
    Archive,
    Ban,
    Bot,
    Circle,
    CircleSlash,
    Cloud,
    Download,
    Eye,
    EyeOff,
    File as FileIcon,
    FileX,
    HardDrive,
    Heart,
    Image,
    Import,
    Music,
    Smile,
    ThumbsUp,
    User,
    Video,
} from 'lucide-vue-next';
import DashboardMetricValue from '@/components/dashboard/DashboardMetricValue.vue';
import type { DashboardMetricDistribution, DashboardMetricIcon } from '@/types/dashboard';
import { formatDashboardCount, formatDashboardPercent } from '@/utils/dashboard';

interface Props {
    distribution: DashboardMetricDistribution;
}

defineProps<Props>();

const segmentIcons = {
    archive: Archive,
    ban: Ban,
    bot: Bot,
    circle: Circle,
    'circle-slash': CircleSlash,
    cloud: Cloud,
    download: Download,
    eye: Eye,
    'eye-off': EyeOff,
    file: FileIcon,
    'file-x': FileX,
    'hard-drive': HardDrive,
    heart: Heart,
    image: Image,
    import: Import,
    music: Music,
    smile: Smile,
    'thumbs-up': ThumbsUp,
    user: User,
    video: Video,
} satisfies Record<DashboardMetricIcon, Component>;

function segmentGridStyle(segmentCount: number): Record<string, string> {
    return {
        gridTemplateColumns: `repeat(${Math.max(1, segmentCount)}, minmax(0, 1fr))`,
    };
}

function segmentIcon(icon: DashboardMetricIcon): Component {
    return segmentIcons[icon];
}
</script>

<template>
    <div class="space-y-2">
        <div class="text-sm font-semibold text-regal-navy-100" :title="distribution.meta">
            {{ distribution.label }}
            <span class="font-medium text-twilight-indigo-300">({{ formatDashboardCount(distribution.total) }})</span>
        </div>

        <div class="flex h-3 w-full overflow-hidden rounded-sm bg-prussian-blue-900">
            <div
                v-for="segment in distribution.segments"
                :key="segment.key"
                class="h-full transition-[width]"
                :class="{ 'min-w-px': segment.value > 0 }"
                :style="{ width: `${segment.barPercent ?? 0}%`, backgroundColor: segment.color }"
                :title="`${segment.label}: ${formatDashboardCount(segment.value)} / ${formatDashboardCount(distribution.total)} (${formatDashboardPercent(segment.barPercent ?? 0)})`"
            />
        </div>

        <div class="grid gap-2" :style="segmentGridStyle(distribution.segments.length)">
            <div
                v-for="segment in distribution.segments"
                :key="segment.key"
                class="min-h-[4.5rem] rounded-sm border border-twilight-indigo-500/30 bg-prussian-blue-700/60 p-3"
            >
                <div class="flex h-full items-start justify-between gap-3">
                    <div class="flex min-w-0 flex-col items-start gap-2">
                        <component
                            :is="segmentIcon(segment.icon)"
                            class="size-4 shrink-0"
                            :style="{ color: segment.color }"
                            aria-hidden="true"
                        />
                        <span class="break-words text-xs leading-snug text-twilight-indigo-200">
                            {{ segment.label }}
                        </span>
                    </div>

                    <div class="shrink-0 text-right">
                        <div class="text-sm font-semibold tabular-nums">
                            <DashboardMetricValue
                                :value="segment.value"
                                :denominator="distribution.total"
                                :color="segment.color"
                            />
                        </div>
                        <div class="mt-1 text-xs tabular-nums text-twilight-indigo-300">
                            {{ formatDashboardPercent(segment.barPercent ?? 0) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
