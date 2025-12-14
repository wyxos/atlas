<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{ class?: HTMLAttributes["class"] }>()
</script>

<template>
  <div
    data-slot="sheet-footer"
    :class="cn('mt-auto flex items-center justify-end gap-4 p-6 border-t-2 border-twilight-indigo-500', props.class)"
  >
    <slot />
  </div>
</template>
