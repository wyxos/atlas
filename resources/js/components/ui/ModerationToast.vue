<script setup lang="ts">
import { onMounted, onBeforeUnmount, reactive, ref } from 'vue'
import { bus } from '@/lib/bus'
import { undoManager } from '@/lib/undo'

interface Row { id: string; label: string; previews: string[]; previewTitles: string[]; count: number }

const state = reactive<{ rows: Row[] }>({ rows: [] })
const undoOffsetPx = ref(0)

function measureUndoHeight() {
  const el = document.querySelector('[data-slot="undo-toast"]') as HTMLElement | null
  undoOffsetPx.value = el ? (el.getBoundingClientRect().height + 16 /* gap */) : 0
}

function pushRow(payload: { ids: number[]; previews: string[]; previewTitles: string[]; count: number }) {
  const label = `Auto-blacklisted ${payload.count} item${payload.count > 1 ? 's' : ''} by rules`
  const row: Row = {
    id: crypto.randomUUID(),
    label,
    previews: (payload.previews || []).slice(0, 4),
    previewTitles: (payload.previewTitles || []).slice(0, 4),
    count: payload.count,
  }
  state.rows.unshift(row)
  setTimeout(() => dismiss(row.id), 6000)
  // Recompute offset in case stacking changes
  requestAnimationFrame(measureUndoHeight)
}

function onNotify(payload: any) {
  try { pushRow(payload as any) } catch {}
}

function dismiss(id: string) {
  state.rows = state.rows.filter(r => r.id !== id)
}

function onUndoEvents() {
  // Any change to undo stack could change height; re-measure
  requestAnimationFrame(measureUndoHeight)
}

onMounted(() => {
  bus.on('moderation:notify' as any, onNotify as any)
  undoManager.bus.on('undo:new' as any, onUndoEvents as any)
  undoManager.bus.on('undo:update' as any, onUndoEvents as any)
  undoManager.bus.on('undo:remove' as any, onUndoEvents as any)
  window.addEventListener('resize', measureUndoHeight)
  // Initial measurement
  measureUndoHeight()
})

onBeforeUnmount(() => {
  bus.off('moderation:notify' as any, onNotify as any)
  undoManager.bus.off('undo:new' as any, onUndoEvents as any)
  undoManager.bus.off('undo:update' as any, onUndoEvents as any)
  undoManager.bus.off('undo:remove' as any, onUndoEvents as any)
  window.removeEventListener('resize', measureUndoHeight)
})
</script>

<template>
  <div v-if="state.rows.length" class="pointer-events-auto fixed right-4 z-[2000] max-w-sm rounded-md border border-red-700 bg-red-600 text-red-50 p-3 shadow-lg space-y-2 dark:bg-red-700 dark:border-red-900" :style="{ bottom: (16 + undoOffsetPx) + 'px' }">
    <div v-for="row in state.rows" :key="row.id" class="rounded border p-2 bg-background">
      <div class="flex items-start gap-3">
        <div v-if="row.previews.length" class="flex -space-x-2">
          <img v-for="(src, i) in row.previews" :key="src + i" :src="src" :title="row.previewTitles[i] || ''" class="h-12 w-12 rounded object-cover border border-border bg-muted" alt="" />
        </div>
        <div class="min-w-0 flex-1 space-y-2">
          <div class="text-sm text-popover-foreground truncate">{{ row.label }}</div>
          <div class="flex items-center gap-2">
            <button class="rounded px-2 py-0.5 text-[11px] text-muted-foreground hover:bg-accent" @click="dismiss(row.id)" aria-label="Dismiss">×</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
