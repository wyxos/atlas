<template>
  <!-- GPU-friendly spinner that animates only transform (compositor), avoiding layout/paint thrash -->
  <div class="loader" aria-hidden="true"></div>
</template>

<script setup lang="ts">
// Simple loader overlay content (no props). Parent controls visibility/position.
</script>

<style scoped>
.loader {
  /* Size kept small to reduce overdraw; parent centers it */
  width: 28px;
  height: 28px;
  border-radius: 50%;
  /* Track and indicator use CSS vars so parents can theme via color */
  border: 2px solid var(--loader-track, rgba(255, 255, 255, 0.25));
  border-top-color: var(--loader-indicator, currentColor);
  /* Animate only transform for maximum performance */
  animation: loader-rotate 0.7s linear infinite;
  will-change: transform;
}

@keyframes loader-rotate {
  to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
  .loader { animation-duration: 2s; }
}
</style>
