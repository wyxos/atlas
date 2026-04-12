<script setup lang="ts">
import { computed } from 'vue'
import { Loader2 } from 'lucide-vue-next'

import Pill from './ui/Pill.vue'

type VibeStatusLike = {
  currentCursor: string | null
  errorMessage: string | null
  fillCollectedCount: number | null
  fillDelayRemainingMs: number | null
  fillTargetCount: number | null
  hasNextPage: boolean
  itemCount: number
  loadState: 'failed' | 'loaded' | 'loading'
  nextCursor: string | null
  phase: 'failed' | 'filling' | 'idle' | 'initializing' | 'loading' | 'refreshing'
  previousCursor: string | null
}

interface Props {
  status: VibeStatusLike
}

const props = defineProps<Props>()

const currentLabel = computed(() => props.status.currentCursor ?? 'N/A')
const nextLabel = computed(() => props.status.nextCursor ?? 'N/A')
const previousLabel = computed(() => props.status.previousCursor ?? 'N/A')

const statusLabel = computed(() => {
  if (props.status.loadState === 'failed') {
    return props.status.errorMessage ?? 'Failed'
  }

  if (props.status.phase === 'filling') {
    if (props.status.fillCollectedCount !== null && props.status.fillTargetCount !== null) {
      const delaySeconds = props.status.fillDelayRemainingMs !== null
        ? ` · ${Math.max(0, props.status.fillDelayRemainingMs / 1000).toFixed(1)}s`
        : ''

      return `Filling ${props.status.fillCollectedCount}/${props.status.fillTargetCount}${delaySeconds}`
    }

    return 'Filling'
  }

  if (props.status.phase === 'refreshing') {
    return 'Refreshing'
  }

  if (props.status.loadState === 'loading') {
    return 'Loading'
  }

  if (props.status.itemCount === 0) {
    return 'No items available'
  }

  if (!props.status.hasNextPage && props.status.itemCount > 0) {
    return 'End of list'
  }

  return 'Loaded'
})

const statusVariant = computed(() => {
  if (props.status.loadState === 'failed') {
    return 'danger'
  }

  if (
    props.status.loadState === 'loading'
    || props.status.phase === 'filling'
    || props.status.phase === 'initializing'
    || props.status.phase === 'loading'
    || props.status.phase === 'refreshing'
  ) {
    return 'warning'
  }

  if (props.status.itemCount === 0) {
    return 'neutral'
  }

  if (!props.status.hasNextPage && props.status.itemCount > 0) {
    return 'neutral'
  }

  return 'success'
})

const isPending = computed(() => (
  props.status.loadState === 'loading'
  || props.status.phase === 'filling'
  || props.status.phase === 'initializing'
  || props.status.phase === 'loading'
  || props.status.phase === 'refreshing'
))
</script>

<template>
  <div
    data-testid="browse-v2-status-bar"
    class="pointer-events-auto flex w-full max-w-[1120px] flex-wrap items-center justify-center gap-x-5 gap-y-2 border border-white/12 bg-black/55 px-4 py-3 backdrop-blur-[18px] sm:px-5"
  >
    <Pill label="Viewing" :value="currentLabel" variant="neutral" reversed data-testid="browse-v2-viewing-pill" />
    <Pill label="Next" :value="nextLabel" variant="secondary" reversed data-testid="browse-v2-next-pill" />
    <Pill label="Previous" :value="previousLabel" variant="secondary" reversed data-testid="browse-v2-previous-pill" />
    <Pill
      label="Status"
      :value="statusLabel"
      :variant="statusVariant"
      reversed
      data-testid="browse-v2-status-pill"
    >
      <template #value>
        <span class="flex items-center gap-2">
          <Loader2
            v-if="isPending"
            :size="14"
            class="animate-spin"
          />
          <span>{{ statusLabel }}</span>
        </span>
      </template>
    </Pill>
    <Pill label="Total" :value="status.itemCount" variant="primary" reversed data-testid="browse-v2-total-pill" />
  </div>
</template>
