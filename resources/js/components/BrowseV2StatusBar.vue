<script setup lang="ts">
import { computed } from 'vue'
import { Ban, Heart, Loader2, LockKeyhole, LockKeyholeOpen, ThumbsDown, ThumbsUp } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import type { LoadedItemsBulkAction } from '@/composables/useTabContentItemInteractions'
import Pill from './ui/Pill.vue'

const bulkActionButtons: Array<{
  action: LoadedItemsBulkAction
  color?: 'danger'
  dataTest: string
  icon: typeof Heart
  label: string
  title: string
}> = [
  {
    action: 'like',
    dataTest: 'loaded-items-like-button',
    icon: ThumbsUp,
    label: 'Like all',
    title: 'Run the ALT + left click reaction on all loaded items',
  },
  {
    action: 'love',
    dataTest: 'loaded-items-love-button',
    icon: Heart,
    label: 'Love all',
    title: 'Run the ALT + middle click reaction on all loaded items',
  },
  {
    action: 'dislike',
    color: 'danger',
    dataTest: 'loaded-items-dislike-button',
    icon: ThumbsDown,
    label: 'Dislike all',
    title: 'Run the ALT + right click reaction on all loaded items',
  },
  {
    action: 'blacklist',
    color: 'danger',
    dataTest: 'loaded-items-blacklist-button',
    icon: Ban,
    label: 'Blacklist all',
    title: 'Blacklist every loaded item and remove it from the grid',
  },
]

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
  nextBoundaryLoadProgress: number
  pageLoadingLocked?: boolean
  phase: 'failed' | 'filling' | 'idle' | 'initializing' | 'loading' | 'refreshing'
  previousBoundaryLoadProgress: number
  previousCursor: string | null
}

interface Props {
  status: VibeStatusLike
  totalAvailable?: number | null
  bulkActionsDisabled?: boolean
  canTogglePageLoadingLock?: boolean
  pageLoadingLocked?: boolean
  performLoadedItemsBulkAction?: ((action: LoadedItemsBulkAction) => void | Promise<number>) | null
  togglePageLoadingLock?: (() => void) | null
}

const props = withDefaults(defineProps<Props>(), {
  totalAvailable: null,
  bulkActionsDisabled: true,
  canTogglePageLoadingLock: false,
  pageLoadingLocked: false,
  performLoadedItemsBulkAction: null,
  togglePageLoadingLock: null,
})

const currentLabel = computed(() => props.status.currentCursor ?? 'N/A')
const nextLabel = computed(() => props.status.nextCursor ?? 'N/A')
const previousLabel = computed(() => props.status.previousCursor ?? 'N/A')
const showActionRail = computed(() => props.performLoadedItemsBulkAction !== null || props.canTogglePageLoadingLock)
const nextBoundaryProgressPercent = computed(() => Math.round(clampProgress(props.status.nextBoundaryLoadProgress) * 100))
const previousBoundaryProgressPercent = computed(() => Math.round(clampProgress(props.status.previousBoundaryLoadProgress) * 100))

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

function handleLoadedItemsBulkAction(action: LoadedItemsBulkAction): void {
  if (!props.performLoadedItemsBulkAction) {
    return
  }

  void props.performLoadedItemsBulkAction(action)
}

function handleTogglePageLoadingLock(): void {
  if (!props.togglePageLoadingLock) {
    return
  }

  props.togglePageLoadingLock()
}

function clampProgress(value: unknown): number {
  const numeric = Number(value)

  if (!Number.isFinite(numeric)) {
    return 0
  }

  return Math.min(Math.max(numeric, 0), 1)
}
</script>

<template>
  <div
    data-testid="browse-v2-status-bar"
    class="pointer-events-auto flex w-fit max-w-full flex-col gap-3 border border-white/12 bg-black/55 px-4 py-3 backdrop-blur-[18px] sm:px-5 lg:flex-row lg:items-center lg:justify-between"
  >
    <div class="flex min-w-0 flex-1 flex-wrap items-center justify-center gap-x-5 gap-y-2 lg:justify-start">
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
      <Pill label="Loaded" :value="status.itemCount" variant="primary" reversed data-testid="browse-v2-loaded-total-pill" />
      <Pill
        v-if="props.totalAvailable !== null && props.totalAvailable !== undefined"
        label="Available"
        :value="props.totalAvailable"
        variant="primary"
        reversed
        data-testid="browse-v2-available-total-pill"
      />
      <div class="grid min-w-[7.5rem] gap-1">
        <span class="text-[0.58rem] font-bold uppercase tracking-[0.22em] text-[#f7f1ea]/46">Prev load</span>
        <div
          data-testid="browse-v2-previous-boundary-progress"
          role="progressbar"
          aria-label="Previous page load proximity"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="previousBoundaryProgressPercent"
          class="relative h-2 w-28 overflow-hidden border border-white/10 bg-white/[0.04]"
        >
          <div
            class="absolute inset-y-0 left-0 bg-sky-300/80 transition-[width] duration-150"
            :class="props.pageLoadingLocked ? 'opacity-45' : ''"
            :style="{ width: `${previousBoundaryProgressPercent}%` }"
          />
        </div>
      </div>
      <div class="grid min-w-[7.5rem] gap-1">
        <span class="text-[0.58rem] font-bold uppercase tracking-[0.22em] text-[#f7f1ea]/46">Next load</span>
        <div
          data-testid="browse-v2-next-boundary-progress"
          role="progressbar"
          aria-label="Next page load proximity"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="nextBoundaryProgressPercent"
          class="relative h-2 w-28 overflow-hidden border border-white/10 bg-white/[0.04]"
        >
          <div
            class="absolute inset-y-0 left-0 bg-amber-300/80 transition-[width] duration-150"
            :class="props.pageLoadingLocked ? 'opacity-45' : ''"
            :style="{ width: `${nextBoundaryProgressPercent}%` }"
          />
        </div>
      </div>
    </div>

    <div
      v-if="showActionRail"
      class="flex items-center justify-center lg:justify-end"
    >
      <div class="flex items-center gap-1 rounded-full border border-white/10 bg-white/5 p-1 shadow-[0_18px_60px_-38px_rgba(0,0,0,0.95)]">
        <Button
          size="icon-sm"
          variant="ghost"
          class="rounded-full border-white/10 bg-transparent text-[#f7f1ea]/78 hover:border-white/20 hover:bg-white/10 hover:text-white"
          :class="props.pageLoadingLocked ? 'border-danger-400/60 bg-danger-500/18 text-danger-100 hover:border-danger-300 hover:bg-danger-500/28 hover:text-white' : ''"
          data-test="page-loading-lock-button"
          :aria-label="props.pageLoadingLocked ? 'Unlock page loading' : 'Lock page loading'"
          :aria-pressed="props.pageLoadingLocked ? 'true' : 'false'"
          :disabled="!props.canTogglePageLoadingLock"
          :title="props.pageLoadingLocked ? 'Unlock Vibe page loading' : 'Lock Vibe page loading'"
          @click="handleTogglePageLoadingLock"
        >
          <component :is="props.pageLoadingLocked ? LockKeyhole : LockKeyholeOpen" :size="14" />
        </Button>

        <Button
          v-for="action in bulkActionButtons"
          :key="action.dataTest"
          size="icon-sm"
          variant="ghost"
          class="rounded-full border-white/10 bg-transparent text-[#f7f1ea]/78 hover:border-white/20 hover:bg-white/10 hover:text-white"
          :class="action.color === 'danger' ? 'text-danger-100 hover:border-danger-400/60 hover:bg-danger-500/20 hover:text-white' : ''"
          :aria-label="action.label"
          :data-test="action.dataTest"
          :disabled="props.bulkActionsDisabled || !props.performLoadedItemsBulkAction"
          :title="action.title"
          @click="handleLoadedItemsBulkAction(action.action)"
        >
          <component :is="action.icon" :size="14" />
        </Button>
      </div>
    </div>
  </div>
</template>
