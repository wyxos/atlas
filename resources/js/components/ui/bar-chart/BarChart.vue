<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

interface BarChartProps {
  data: {
    name: string;
    value: number;
  }[];
  className?: string;
}

const props = defineProps<BarChartProps>();

// Calculate the maximum value for scaling the bars
const maxValue = computed(() => {
  return Math.max(...props.data.map(item => item.value));
});

// Calculate percentages for bar widths
const getPercentage = (value: number): number => {
  return maxValue.value > 0 ? (value / maxValue.value) * 100 : 0;
};
</script>

<template>
  <div :class="cn('space-y-4', className)">
    <div v-for="(item, index) in data" :key="index" class="space-y-1">
      <div class="flex justify-between">
        <span class="text-sm font-medium">{{ item.name }}</span>
        <span class="text-sm font-medium">{{ item.value }}</span>
      </div>
      <div class="h-4 w-full bg-secondary rounded-full overflow-hidden">
        <div
          class="h-full rounded-full"
          :class="{
            'bg-blue-600': index === 0,
            'bg-green-600': index === 1,
            'bg-purple-600': index === 2,
            'bg-yellow-600': index === 3,
            'bg-gray-600': index === 4,
          }"
          :style="{ width: getPercentage(item.value) + '%' }"
        ></div>
      </div>
    </div>
  </div>
</template>
