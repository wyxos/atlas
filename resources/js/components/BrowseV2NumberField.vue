<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Minus, Plus } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'

const props = withDefaults(defineProps<{
  ariaLabel: string
  disabled?: boolean
  inputTest: string
  max: number
  min: number
  modelValue: number
  step?: number
  title: string
}>(), {
  disabled: false,
  step: 1,
})

const emit = defineEmits<{
  'update:modelValue': [value: number]
}>()

const draft = ref(String(clampInteger(props.modelValue)))
const isEditing = ref(false)
const decrementDisabled = computed(() => props.disabled || clampInteger(props.modelValue) <= props.min)
const incrementDisabled = computed(() => props.disabled || clampInteger(props.modelValue) >= props.max)

watch(
  () => [props.modelValue, props.min, props.max] as const,
  () => {
    if (!isEditing.value) {
      draft.value = String(clampInteger(props.modelValue))
    }
  },
)

function handleFocus(): void {
  isEditing.value = true
}

function handleInput(event: Event): void {
  const target = event.target as HTMLInputElement | null
  const nextDraft = normalizeDraft(target?.value ?? '')

  draft.value = nextDraft
  if (target) {
    target.value = nextDraft
  }
}

function commitDraft(): void {
  const nextValue = clampInteger(Number(draft.value))
  isEditing.value = false
  draft.value = String(nextValue)
  emit('update:modelValue', nextValue)
}

function resetDraft(): void {
  isEditing.value = false
  draft.value = String(clampInteger(props.modelValue))
}

function stepValue(direction: -1 | 1): void {
  const nextValue = clampInteger(clampInteger(props.modelValue) + direction * props.step)

  isEditing.value = false
  draft.value = String(nextValue)
  emit('update:modelValue', nextValue)
}

function normalizeDraft(value: string): string {
  const digits = value.replace(/\D+/g, '').replace(/^0+(?=\d)/, '')

  if (!digits.length) {
    return ''
  }

  return String(Math.min(Number(digits), props.max))
}

function clampInteger(value: number): number {
  if (!Number.isFinite(value)) {
    return props.min
  }

  return Math.min(Math.max(Math.round(value), props.min), props.max)
}
</script>

<template>
  <span class="inline-flex h-8 items-center overflow-hidden rounded-full border border-white/10 bg-black/25 text-[#f7f1ea]/82 transition focus-within:border-sky-300/50">
    <Button
      size="icon-sm"
      variant="ghost"
      class="h-8 w-7 rounded-none border-0 bg-transparent px-0 text-[#f7f1ea]/62 hover:bg-white/10 hover:text-white"
      :disabled="decrementDisabled"
      :aria-label="`Decrease ${ariaLabel}`"
      :title="`Decrease ${title}`"
      @click="stepValue(-1)"
    >
      <Minus :size="12" />
    </Button>
    <input
      type="text"
      inputmode="numeric"
      pattern="[0-9]*"
      :data-test="inputTest"
      :aria-label="ariaLabel"
      :title="title"
      :value="draft"
      :disabled="disabled"
      class="h-8 w-11 border-x border-white/10 bg-transparent px-1 text-center text-[0.68rem] font-semibold tabular-nums text-[#f7f1ea]/82 outline-none disabled:cursor-not-allowed disabled:opacity-45"
      @focus="handleFocus"
      @input="handleInput"
      @blur="commitDraft"
      @keydown.enter.prevent="commitDraft"
      @keydown.escape.prevent="resetDraft"
    >
    <Button
      size="icon-sm"
      variant="ghost"
      class="h-8 w-7 rounded-none border-0 bg-transparent px-0 text-[#f7f1ea]/62 hover:bg-white/10 hover:text-white"
      :disabled="incrementDisabled"
      :aria-label="`Increase ${ariaLabel}`"
      :title="`Increase ${title}`"
      @click="stepValue(1)"
    >
      <Plus :size="12" />
    </Button>
  </span>
</template>
