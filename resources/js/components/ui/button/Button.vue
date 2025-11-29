<script setup lang="ts">
import type { PrimitiveProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import type { ButtonVariants } from "."
import { Primitive } from "reka-ui"
import { cn } from "@/lib/utils"
import { buttonVariants } from "."
import { Loader2 } from "lucide-vue-next"
import { computed, useAttrs } from "vue"

interface Props extends PrimitiveProps {
  variant?: ButtonVariants["variant"]
  size?: ButtonVariants["size"]
  class?: HTMLAttributes["class"]
  loading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  as: "button",
  loading: false,
})

const attrs = useAttrs()
const isDisabled = computed(() => {
  return props.loading || (attrs.disabled as boolean | undefined) === true
})
</script>

<template>
  <Primitive data-slot="button" :as="as" :as-child="asChild"
    :class="cn(buttonVariants({ variant, size }), 'relative', props.class)" :disabled="isDisabled">
    <!-- Keep content in flow to preserve width/height; hide visually while loading -->
    <span :class="['inline-flex items-center gap-2', loading ? 'opacity-0 pointer-events-none' : '']">
      <slot />
    </span>

    <!-- Centered loader that does not affect layout -->
    <span v-if="loading" class="absolute inset-0 flex items-center justify-center">
      <Loader2 :size="size === 'sm' ? 16 : size === 'lg' ? 24 : 20" class="animate-spin" />
    </span>
  </Primitive>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
