<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

interface PieChartProps {
  data: {
    name: string;
    value: number;
    size?: number;
  }[];
  className?: string;
}

const props = defineProps<PieChartProps>();

// Calculate total value
const totalValue = computed(() => {
  return props.data.reduce((sum, item) => sum + item.value, 0);
});

// Calculate angles for each segment
const segments = computed(() => {
  let cumulativeAngle = 0;
  const colors = [
    '#2563eb', // strong blue
    '#059669', // strong emerald
    '#7c3aed', // strong purple
    '#dc2626', // strong red
    '#374151', // strong gray
  ];

  return props.data.map((item, index) => {
    const percentage = totalValue.value > 0 ? (item.value / totalValue.value) * 100 : 0;
    const angle = (item.value / totalValue.value) * 360;
    const startAngle = cumulativeAngle;
    const endAngle = cumulativeAngle + angle;
    
    // Create SVG path for the segment
    const radius = 80;
    const centerX = 100;
    const centerY = 100;
    
    const startAngleRad = (startAngle * Math.PI) / 180;
    const endAngleRad = (endAngle * Math.PI) / 180;
    
    const x1 = centerX + radius * Math.cos(startAngleRad);
    const y1 = centerY + radius * Math.sin(startAngleRad);
    const x2 = centerX + radius * Math.cos(endAngleRad);
    const y2 = centerY + radius * Math.sin(endAngleRad);
    
    const largeArcFlag = angle > 180 ? 1 : 0;
    
    const pathData = [
      `M ${centerX} ${centerY}`,
      `L ${x1} ${y1}`,
      `A ${radius} ${radius} 0 ${largeArcFlag} 1 ${x2} ${y2}`,
      'Z'
    ].join(' ');
    
    cumulativeAngle += angle;
    
    return {
      ...item,
      percentage,
      angle,
      startAngle,
      endAngle,
      color: colors[index % colors.length],
      pathData
    };
  });
});
</script>

<template>
  <div :class="cn('w-full', className)">
    <div class="flex flex-col items-center">
      <!-- Total Files Count -->
      <div class="text-center mb-4">
        <div class="text-2xl font-bold text-foreground">{{ totalValue.toLocaleString() }}</div>
        <div class="text-sm text-muted-foreground">Total Files</div>
      </div>
      
      <!-- Pie Chart SVG -->
      <div class="flex justify-center">
        <svg width="200" height="200" viewBox="0 0 200 200" class="transform -rotate-90">
          <g v-for="(segment, index) in segments" :key="index">
            <path
              :d="segment.pathData"
              :fill="segment.color"
              :stroke="'white'"
              stroke-width="2"
              class="transition-all duration-300 hover:opacity-90 hover:stroke-white"
              :style="{ filter: 'drop-shadow(0 2px 4px rgba(0, 0, 0, 0.15))' }"
            />
          </g>
        </svg>
      </div>
      
      <!-- Legend -->
      <div class="grid grid-cols-2 gap-3 mt-6 w-full max-w-sm">
        <div 
          v-for="(segment, index) in segments" 
          :key="index"
          class="flex items-center space-x-2 text-sm"
        >
          <div 
            class="w-4 h-4 rounded flex-shrink-0 border border-white/20"
            :style="{ backgroundColor: segment.color }"
          ></div>
          <div class="flex-1 min-w-0">
            <div class="text-foreground font-medium truncate">{{ segment.name.replace(' Files', '') }}</div>
            <div class="text-xs text-muted-foreground">{{ segment.value }} files</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
