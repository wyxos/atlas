<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

interface HorizontalBarChartProps {
  data: {
    name: string;
    value: number;
  }[];
  className?: string;
  colorScheme?: 'rating' | 'default';
}

const props = defineProps<HorizontalBarChartProps>();

// Calculate the maximum value for scaling the bars
const maxValue = computed(() => {
  return Math.max(...props.data.map(item => item.value));
});

// Calculate width percentages for horizontal bars
const getWidthPercentage = (value: number): number => {
  return maxValue.value > 0 ? (value / maxValue.value) * 100 : 0;
};

// Colors for each bar - use appropriate colors based on scheme
const getBarColor = (index: number, name: string): string => {
  if (props.colorScheme === 'rating') {
    const ratingColors = {
      'Loved': 'bg-red-500',      // Heart - Red
      'Liked': 'bg-green-500',    // Thumbs up - Green
      'Disliked': 'bg-orange-500', // Thumbs down - Orange
      'Funny': 'bg-yellow-500',   // Laugh - Yellow
      'No Rating': 'bg-gray-500'  // Neutral - Gray
    };
    return ratingColors[name as keyof typeof ratingColors] || 'bg-blue-500';
  }
  
  const defaultColors = [
    'bg-blue-500',
    'bg-green-500',
    'bg-purple-500',
    'bg-yellow-500',
    'bg-gray-500'
  ];
  return defaultColors[index % defaultColors.length];
};
</script>

<template>
  <div :class="cn('w-full', className)">
    <!-- Chart container -->
    <div class="space-y-3">
      <div 
        v-for="(item, index) in data" 
        :key="index" 
        class="flex items-center space-x-3"
      >
        <!-- Label -->
        <div class="w-16 text-xs font-medium text-muted-foreground text-right">
          {{ item.name }}
        </div>
        
        <!-- Bar container -->
        <div class="flex-1 relative">
          <!-- Background bar -->
          <div class="w-full h-6 bg-muted rounded">
            <!-- Actual bar -->
            <div
              :class="cn('h-6 rounded transition-all duration-300 hover:opacity-80', getBarColor(index, item.name))"
              :style="{ width: getWidthPercentage(item.value) + '%' }"
            ></div>
          </div>
          
          <!-- Value label -->
          <div class="absolute right-2 top-1/2 transform -translate-y-1/2 text-xs font-medium text-white">
            {{ item.value }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
