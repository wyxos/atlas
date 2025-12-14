<script setup lang="ts">
import type { RadioGroupItemEmits, RadioGroupItemProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { RadioGroupItem, RadioGroupIndicator, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"

interface RadioGroupItemComponentProps extends RadioGroupItemProps {
  class?: HTMLAttributes["class"]
}

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<RadioGroupItemComponentProps>()
const emits = defineEmits<RadioGroupItemEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <RadioGroupItem
    data-slot="radio-group-item"
    :class="cn(
      'aspect-square h-4 w-4 rounded-full border-2 border-twilight-indigo-400 text-smart-blue-600 ring-offset-prussian-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-smart-blue-400 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
      'data-[checked]:border-smart-blue-600 data-[checked]:bg-smart-blue-600',
      props.class
    )"
    v-bind="{ ...$attrs, ...forwarded }"
  >
    <RadioGroupIndicator
      class="flex items-center justify-center"
      :class="cn(
        'h-2 w-2 rounded-full bg-white',
        'data-[checked]:block data-[unchecked]:hidden'
      )"
    />
  </RadioGroupItem>
</template>

