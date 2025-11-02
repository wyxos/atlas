<script setup lang="ts">
import { computed } from 'vue'

import { badgeClassForSlot } from '@/pages/browse/highlight'

const props = defineProps<{
  label: string
  count: number
  slotIndex: number
}>()

const badgeClass = computed(() => badgeClassForSlot(props.slotIndex))
</script>

<template>
  <div
    class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-[11px] font-medium text-background"
    :class="badgeClass"
  >
    <span>{{ label }} · {{ count }}</span>
  </div>
</template>
