<script setup lang="ts">
import { computed } from 'vue'
import { Ban, Heart, Loader2, LockKeyhole, LockKeyholeOpen, ThumbsDown, ThumbsUp, X } from 'lucide-vue-next'

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
  fillCompletedCalls: number
  fillCursor?: string | null
  fillDelayRemainingMs: number | null
  fillLoadedCount: number
  fillMode: 'count' | 'cursor' | 'end' | 'idle'
  fillProgress: number | null
  fillTargetCalls: number | null
  fillTargetCount: number | null
  fillTotalCount: number | null
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
  cancelFill?: (() => void) | null
  canTogglePageLoadingLock?: boolean
  pageLoadingLocked?: boolean
  performLoadedItemsBulkAction?: ((action: LoadedItemsBulkAction) => void | Promise<number>) | null
  togglePageLoadingLock?: (() => void) | null
}

const props = withDefaults(defineProps<Props>(), {
  totalAvailable: null,
  bulkActionsDisabled: true,
  cancelFill: null,
  canTogglePageLoadingLock: false,
  pageLoadingLocked: false,
  performLoadedItemsBulkAction: null,
  togglePageLoadingLock: null,
})

const currentLabel = computed(() => props.status.currentCursor ?? 'N/A')
const canCancelFill = computed(() => props.status.fillMode !== 'idle' && props.cancelFill !== null)
const nextLabel = computed(() => {
  if (props.status.phase === 'filling' && props.status.fillCursor) {
    return props.status.fillCursor
  }

  return props.status.nextCursor ?? 'N/A'
})
const previousLabel = computed(() => props.status.previousCursor ?? 'N/A')
const showActionRail = computed(() => canCancelFill.value || props.performLoadedItemsBulkAction !== null || props.canTogglePageLoadingLock)
const nextBoundaryProgressPercent = computed(() => Math.round(clampProgress(props.status.nextBoundaryLoadProgress) * 100))
const previousBoundaryProgressPercent = computed(() => Math.round(clampProgress(props.status.previousBoundaryLoadProgress) * 100))

const statusLabel = computed(() => {
  if (props.status.loadState === 'failed') {
    return props.status.errorMessage ?? 'Failed'
  }

  if (props.status.phase === 'filling') {
    const delaySeconds = props.status.fillDelayRemainingMs !== null
      ? ` · ${Math.max(0, props.status.fillDelayRemainingMs / 1000).toFixed(1)}s`
      : ''

    if (props.status.fillMode === 'count' && props.status.fillTargetCalls !== null) {
      return `Filling ${props.status.fillCompletedCalls}/${props.status.fillTargetCalls} calls${delaySeconds}`
    }

    if (props.status.fillMode === 'end') {
      if (props.status.fillTotalCount !== null) {
        return `Filling ${props.status.fillLoadedCount}/${props.status.fillTotalCount}${delaySeconds}`
      }

      if (props.status.fillCompletedCalls > 0) {
        return `Filling ${props.status.fillLoadedCount} loaded · ${props.status.fillCompletedCalls} calls${delaySeconds}`
      }
    }

    if (props.status.fillCollectedCount !== null && props.status.fillTargetCount !== null) {
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

function handleCancelFill(): void {
  props.cancelFill?.()
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
      <Pill
        label="Prev load"
        value=""
        variant="info"
        reversed
        data-testid="browse-v2-previous-boundary-pill"
      >
        <template #value>
          <span class="flex min-w-[7.5rem] items-center gap-2">
            <span
              data-testid="browse-v2-previous-boundary-progress"
              role="progressbar"
              aria-label="Previous page load proximity"
              aria-valuemin="0"
              aria-valuemax="100"
              :aria-valuenow="previousBoundaryProgressPercent"
              class="relative h-2 flex-1 overflow-hidden rounded-full border border-white/10 bg-white/[0.08]"
            >
              <span
                class="absolute inset-y-0 left-0 rounded-full bg-sky-300/80 transition-[width] duration-150"
                :class="props.pageLoadingLocked ? 'opacity-45' : ''"
                :style="{ width: `${previousBoundaryProgressPercent}%` }"
              />
            </span>
            <span class="min-w-[2.25rem] text-right tabular-nums">{{ previousBoundaryProgressPercent }}%</span>
          </span>
        </template>
      </Pill>
      <Pill
        label="Next load"
        value=""
        variant="warning"
        reversed
        data-testid="browse-v2-next-boundary-pill"
      >
        <template #value>
          <span class="flex min-w-[7.5rem] items-center gap-2">
            <span
              data-testid="browse-v2-next-boundary-progress"
              role="progressbar"
              aria-label="Next page load proximity"
              aria-valuemin="0"
              aria-valuemax="100"
              :aria-valuenow="nextBoundaryProgressPercent"
              class="relative h-2 flex-1 overflow-hidden rounded-full border border-white/10 bg-white/[0.08]"
            >
              <span
                class="absolute inset-y-0 left-0 rounded-full bg-amber-300/80 transition-[width] duration-150"
                :class="props.pageLoadingLocked ? 'opacity-45' : ''"
                :style="{ width: `${nextBoundaryProgressPercent}%` }"
              />
            </span>
            <span class="min-w-[2.25rem] text-right tabular-nums">{{ nextBoundaryProgressPercent }}%</span>
          </span>
        </template>
      </Pill>
    </div>

    <div
      v-if="showActionRail"
      class="flex items-center justify-center lg:justify-end"
    >
      <div class="flex items-center gap-1 rounded-full border border-white/10 bg-white/5 p-1 shadow-[0_18px_60px_-38px_rgba(0,0,0,0.95)]">
        <Button
          v-if="canCancelFill"
          size="icon-sm"
          variant="ghost"
          class="rounded-full border-danger-400/60 bg-danger-500/18 text-danger-100 hover:border-danger-300 hover:bg-danger-500/28 hover:text-white"
          data-test="cancel-fill-button"
          aria-label="Cancel fill"
          title="Cancel Vibe fill"
          @click="handleCancelFill"
        >
          <X :size="14" />
        </Button>

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
