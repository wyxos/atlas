<template>
  <label
    :for="htmlFor"
    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
  >
    <slot />
  </label>
</template>

<script setup lang="ts">
defineProps<{
  htmlFor?: string
}>()
</script>
