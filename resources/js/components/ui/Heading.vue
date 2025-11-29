<script setup lang="ts">
import { computed } from 'vue'
import { cn } from '@/lib/utils'

interface Props {
  as?: 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6'
  size?: 'xs' | 'sm' | 'base' | 'lg' | 'xl' | '2xl' | '3xl' | '4xl' | '5xl'
  weight?: 'normal' | 'medium' | 'semibold' | 'bold'
  color?: 'default' | 'smart-blue' | 'regal-navy' | 'sapphire'
}

const props = withDefaults(defineProps<Props>(), {
  as: 'h2',
  size: 'base',
  weight: 'semibold',
  color: 'default',
})

const sizeClasses = {
  xs: 'text-xs',
  sm: 'text-sm',
  base: 'text-base',
  lg: 'text-lg',
  xl: 'text-xl',
  '2xl': 'text-2xl',
  '3xl': 'text-3xl',
  '4xl': 'text-4xl',
  '5xl': 'text-5xl',
}

const weightClasses = {
  normal: 'font-normal',
  medium: 'font-medium',
  semibold: 'font-semibold',
  bold: 'font-bold',
}

const colorClasses = {
  default: 'text-smart-blue-100',
  'smart-blue': 'text-smart-blue-100',
  'regal-navy': 'text-regal-navy-100',
  sapphire: 'text-sapphire-100',
}

const classes = computed(() => {
  return cn(
    sizeClasses[props.size],
    weightClasses[props.weight],
    colorClasses[props.color]
  )
})
</script>

<template>
  <component :is="as" :class="classes">
    <slot />
  </component>
</template>

