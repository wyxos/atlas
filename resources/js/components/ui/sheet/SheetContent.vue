<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { X } from "lucide-vue-next"
import {
  DialogClose,
  DialogContent,
  DialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"
import SheetOverlay from "./SheetOverlay.vue"

interface SheetContentProps extends DialogContentProps {
  class?: HTMLAttributes["class"]
  side?: "top" | "right" | "bottom" | "left"
}

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<SheetContentProps>(), {
  side: "right",
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class", "side")

const forwarded = useForwardPropsEmits(delegatedProps, emits)

function handlePointerDownOutside(event: Event): void {
  const target = event.target as Element | null
  if (!target) {
    return
  }
  
  // Check if the click is inside a Popover (Select dropdown)
  const clickedInsidePopover = target.closest('[data-slot="popover-content"]') !== null
    || target.closest('[role="listbox"]') !== null
    || target.closest('[data-test="select-item"]') !== null
  
  // Prevent closing if clicking inside a popover
  if (clickedInsidePopover) {
    event.preventDefault()
    event.stopPropagation()
  }
}

function handleInteractOutside(event: Event): void {
  const target = event.target as Element | null
  if (!target) {
    return
  }
  
  // Check if the interaction is inside a Popover (Select dropdown)
  const clickedInsidePopover = target.closest('[data-slot="popover-content"]') !== null
    || target.closest('[role="listbox"]') !== null
    || target.closest('[data-test="select-item"]') !== null
  
  // Prevent closing if interacting inside a popover
  if (clickedInsidePopover) {
    event.preventDefault()
    event.stopPropagation()
  }
}
</script>

<template>
  <DialogPortal>
    <SheetOverlay />
    <DialogContent
      data-slot="sheet-content"
      :class="cn(
        'bg-prussian-blue-600 data-[state=open]:animate-in data-[state=closed]:animate-out fixed z-50 flex flex-col gap-4 shadow-2xl transition ease-in-out data-[state=closed]:duration-300 data-[state=open]:duration-500',
        side === 'right'
          && 'data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right inset-y-0 right-0 h-full w-3/4 border-l-2 border-twilight-indigo-500 sm:max-w-lg',
        side === 'left'
          && 'data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left inset-y-0 left-0 h-full w-3/4 border-r-2 border-twilight-indigo-500 sm:max-w-sm',
        side === 'top'
          && 'data-[state=closed]:slide-out-to-top data-[state=open]:slide-in-from-top inset-x-0 top-0 h-auto border-b-2 border-twilight-indigo-500',
        side === 'bottom'
          && 'data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom inset-x-0 bottom-0 h-auto border-t-2 border-twilight-indigo-500',
        props.class)"
      v-bind="{ ...$attrs, ...forwarded }"
      @pointer-down-outside="handlePointerDownOutside"
      @interact-outside="handleInteractOutside"
    >
      <slot />

      <DialogClose
        class="absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-smart-blue-400 focus:ring-offset-2 focus:ring-offset-prussian-blue-600 focus:outline-hidden disabled:pointer-events-none text-twilight-indigo-100"
      >
        <X class="size-4" />
        <span class="sr-only">Close</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
