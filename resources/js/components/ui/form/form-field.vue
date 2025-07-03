<template>
  <div :class="$attrs.class">
    <slot />
  </div>
</template>

<script setup lang="ts">
defineProps<{
  id?: string
  name: string
  invalid?: boolean
  error?: string
}>()
</script>
