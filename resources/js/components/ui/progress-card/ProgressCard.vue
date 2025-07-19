<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

interface ProgressCardProps {
  title: string;
  value: number;
  total: number;
  variant?: 'success' | 'warning' | 'error' | 'info';
  className?: string;
}

const props = defineProps<ProgressCardProps>();

const percentage = computed(() => {
  return props.total > 0 ? Math.round((props.value / props.total) * 100) : 0;
});

const getVariantClasses = () => {
  const variants = {
    success: {
      progress: 'bg-green-500',
      background: 'bg-green-100 dark:bg-green-900/20',
      text: 'text-green-700 dark:text-green-300'
    },
    warning: {
      progress: 'bg-yellow-500',
      background: 'bg-yellow-100 dark:bg-yellow-900/20',
      text: 'text-yellow-700 dark:text-yellow-300'
    },
    error: {
      progress: 'bg-red-500',
      background: 'bg-red-100 dark:bg-red-900/20',
      text: 'text-red-700 dark:text-red-300'
    },
    info: {
      progress: 'bg-blue-500',
      background: 'bg-blue-100 dark:bg-blue-900/20',
      text: 'text-blue-700 dark:text-blue-300'
    }
  };
  
  return variants[props.variant || 'info'];
};
</script>

<template>
  <div :class="cn('p-4 rounded-lg border', getVariantClasses().background, className)">
    <!-- Header -->
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-medium text-foreground">{{ title }}</h3>
      <span :class="cn('text-sm font-bold', getVariantClasses().text)">
        {{ percentage }}%
      </span>
    </div>
    
    <!-- Progress bar -->
    <div class="w-full bg-muted rounded-full h-2 mb-3">
      <div
        :class="cn('h-2 rounded-full transition-all duration-300', getVariantClasses().progress)"
        :style="{ width: percentage + '%' }"
      ></div>
    </div>
    
    <!-- Values -->
    <div class="flex justify-between text-xs text-muted-foreground">
      <span>{{ value.toLocaleString() }} of {{ total.toLocaleString() }}</span>
      <span>{{ (total - value).toLocaleString() }} remaining</span>
    </div>
  </div>
</template>
