<template>
  <p
    v-if="$slots.default"
    class="text-sm font-medium text-destructive"
  >
    <slot />
  </p>
</template>
