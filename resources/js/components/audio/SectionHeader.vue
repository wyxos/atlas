<script setup lang="ts">
import { Music } from 'lucide-vue-next';

const props = withDefaults(defineProps<{
  title: string;
  count?: number;
  icon?: any; // lucide component
}>(), {
  count: undefined,
  icon: Music,
});
</script>

<template>
  <div class="hidden md:flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
      <component :is="props.icon" :size="24" />
      <h2 class="text-xl font-semibold">{{ title }}</h2>
    </div>
    <p v-if="typeof count === 'number'" class="text-sm text-muted-foreground">
      <strong>{{ count.toLocaleString() }}</strong> tracks
    </p>
  </div>
</template>

