<template>
  <form
    :class="$attrs.class ?? ''"
    @submit="handleSubmit"
  >
    <slot />
  </form>
</template>

<script setup lang="ts">
import { useSlots } from 'vue'

const slots = useSlots()

const emit = defineEmits<{
  (e: 'submit', event: Event): void
}>()

function handleSubmit(event: Event) {
  emit('submit', event)
}
</script>
