<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

interface BarChartProps {
  data: {
    name: string;
    value: number;
  }[];
  className?: string;
  colorScheme?: 'status' | 'metadata' | 'rating' | 'default';
}

const props = defineProps<BarChartProps>();

// Calculate the maximum value for scaling the bars
const maxValue = computed(() => {
  return Math.max(...props.data.map(item => item.value));
});

// Calculate height percentages for vertical bars
const getHeightPercentage = (value: number): number => {
  return maxValue.value > 0 ? (value / maxValue.value) * 100 : 0;
};

// Colors for each bar - use appropriate colors based on scheme
const getBarColor = (index: number): string => {
  const colorSchemes = {
    status: [
      'bg-red-500',      // Not Found - Red (error)
      'bg-yellow-500',   // No Metadata - Yellow (warning)
      'bg-orange-500',   // Needs Review - Orange (attention)
      'bg-blue-500',     // Fallback
    ],
    metadata: [
      'bg-green-500',    // With Metadata - Green (good)
      'bg-gray-500',     // Without Metadata - Gray (neutral)
      'bg-yellow-500',   // Review Required - Yellow (warning)
      'bg-blue-500',     // Review Not Required - Blue (good)
    ],
    rating: [
      'bg-red-500',      // Loved - Red (heart)
      'bg-blue-500',     // Liked - Blue (thumbs up)
      'bg-orange-500',   // Disliked - Orange (thumbs down)
      'bg-gray-500',     // No Rating - Gray (neutral)
    ],
    default: [
      'bg-blue-500',
      'bg-green-500',
      'bg-purple-500',
      'bg-yellow-500',
      'bg-gray-500'
    ]
  };
  
  const scheme = props.colorScheme || 'default';
  const colors = colorSchemes[scheme];
  return colors[index % colors.length];
};
</script>

<template>
  <div :class="cn('w-full', className)">
    <!-- Chart container -->
    <div class="flex items-end justify-center space-x-3 h-48 mb-4">
      <div 
        v-for="(item, index) in data" 
        :key="index" 
        class="flex flex-col items-center space-y-2"
      >
        <!-- Bar -->
        <div class="relative flex items-end h-32">
          <div
            :class="cn('w-12 rounded-t-md transition-all duration-300 hover:opacity-80', getBarColor(index))"
            :style="{ height: getHeightPercentage(item.value) + '%' }"
          ></div>
          <!-- Value label on top of bar -->
          <div class="absolute -top-5 left-1/2 transform -translate-x-1/2 text-xs font-medium text-foreground">
            {{ item.value }}
          </div>
        </div>
        <!-- Category label -->
        <div class="text-xs text-center font-medium text-muted-foreground max-w-12">
          {{ item.name.replace(' Files', '') }}
        </div>
      </div>
    </div>
    
    <!-- Legend -->
    <div class="flex flex-wrap justify-center gap-4 mt-4">
      <div 
        v-for="(item, index) in data" 
        :key="index"
        class="flex items-center space-x-2"
      >
        <div :class="cn('w-3 h-3 rounded', getBarColor(index))"></div>
        <span class="text-sm text-foreground">{{ item.name }}</span>
      </div>
    </div>
  </div>
</template>
