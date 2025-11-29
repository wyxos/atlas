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
  <Primitive
    data-slot="button"
    :as="as"
    :as-child="asChild"
    :class="cn(buttonVariants({ variant, size }), 'relative', props.class)"
    :disabled="isDisabled"
  >
    <span class="invisible">
      <slot />
    </span>
    <span class="absolute inset-0 flex items-center justify-center">
      <Transition name="fade" mode="out-in">
        <Loader2 v-if="loading" :size="size === 'sm' ? 16 : size === 'lg' ? 24 : 20" class="animate-spin" />
        <span v-else>
          <slot />
        </span>
      </Transition>
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
