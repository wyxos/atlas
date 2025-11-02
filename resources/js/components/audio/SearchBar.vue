<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Search, Loader2 } from 'lucide-vue-next';

defineProps<{
  modelValue: string;
  loading?: boolean;
  placeholder?: string;
  label?: string;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();
</script>

<template>
  <div class="mb-4">
    <div class="relative max-w-md">
      <Label :for="label ?? 'search'" class="sr-only">{{ label ?? 'Search' }}</Label>
      <div class="relative">
        <Search v-if="!loading" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
        <Loader2 v-else :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground animate-spin" />
        <Input
          :id="label ?? 'search'"
          :modelValue="modelValue"
          type="search"
          :placeholder="placeholder ?? 'Search...'"
          class="pl-10"
          @update:modelValue="(v: string) => emit('update:modelValue', v)"
        />
      </div>
    </div>
  </div>
</template>

