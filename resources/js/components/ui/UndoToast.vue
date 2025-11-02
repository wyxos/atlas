<script setup lang="ts">
import { onMounted, onBeforeUnmount, reactive } from 'vue'
import { undoManager, type UndoAction } from '@/lib/undo'

type Row = { id: string; label: string; remainingMs: number; paused: boolean; previews: string[]; previewTitles: string[]; variant?: 'default' | 'destructive' | 'info' }

const state = reactive<{ rows: Row[]; allPaused: boolean }>({ rows: [], allPaused: false })
let intervalId: number | null = null

function getActionById(id: string): UndoAction | undefined {
  return undoManager.stack.find(a => a.id === id)
}

function refresh() {
  const now = Date.now()
  const pausedMap = new Map(state.rows.map(r => [r.id, r.paused]))
  state.rows = undoManager.stack.slice(0, 5).map(a => ({
    id: a.id,
    label: a.label,
    remainingMs: Math.max(0, a.expiresAt - now),
    paused: state.allPaused ? true : (pausedMap.get(a.id) ?? false),
    previews: (a.previews || []).slice(0, 4),
    previewTitles: (a.previewTitles || []).slice(0, 4),
    variant: a.variant || 'default',
  }))
}

function startTicker() {
  stopTicker()
  intervalId = window.setInterval(() => {
    const now = Date.now()
    for (const r of state.rows) {
      if (r.paused) continue
      const a = getActionById(r.id)
      if (!a) continue
      r.remainingMs = Math.max(0, a.expiresAt - now)
    }
  }, 30)
}
function stopTicker() {
  if (intervalId != null) {
    clearInterval(intervalId)
    intervalId = null
  }
}

function onNew(a: UndoAction) {
  // If the snackbar is globally paused, pause new actions as they arrive
  if (state.allPaused) undoManager.pause(a.id)
  refresh()
}
function onRemove() { refresh() }
function onUpdate() { refresh() }

function onUndo(id: string) { void undoManager.undo(id) }
function onDismiss(id: string) { undoManager.dismiss(id) }


function onMouseEnterAll() {
  state.allPaused = true
  for (const r of state.rows) {
    if (!r.paused) {
      r.paused = true
      undoManager.pause(r.id)
    }
  }
}
function onMouseLeaveAll() {
  state.allPaused = false
  for (const r of state.rows) {
    if (r.paused) {
      r.paused = false
      undoManager.resume(r.id)
    }
  }
}

function isDialogOpen(): boolean {
  // Rely on our Dialog implementation data-slot markers
  return !!document.querySelector('[data-slot="dialog-overlay"], [data-slot="dialog-content"]')
}

function onKeyDown(e: KeyboardEvent) {
  if (state.rows.length === 0) return
  if (e.key === 'Escape') {
    // Give priority to full-size dialog ESC
    if (isDialogOpen()) return
    e.preventDefault()
    undoManager.dismiss(state.rows[0].id)
  }
}

onMounted(() => {
  refresh()
  startTicker()
  undoManager.bus.on('undo:new', onNew)
  undoManager.bus.on('undo:remove', onRemove)
  undoManager.bus.on('undo:update', onUpdate)
  window.addEventListener('keydown', onKeyDown as any, { passive: false })
})

onBeforeUnmount(() => {
  undoManager.bus.off('undo:new', onNew as any)
  undoManager.bus.off('undo:remove', onRemove as any)
  undoManager.bus.off('undo:update', onUpdate as any)
  window.removeEventListener('keydown', onKeyDown as any)
  stopTicker()
})
</script>

<template>
<div v-if="state.rows.length" data-slot="undo-toast" class="pointer-events-auto fixed bottom-4 right-4 z-[2000] max-w-sm rounded-md border bg-popover p-3 shadow-lg space-y-2" @mouseenter="onMouseEnterAll" @mouseleave="onMouseLeaveAll">
    <div v-for="row in state.rows" :key="row.id" class="rounded border p-2" :class="row.variant === 'destructive' ? 'bg-red-50 text-red-900 border-red-300 dark:bg-red-950/40 dark:text-red-200 dark:border-red-800' : 'bg-background'">
      <div class="flex items-start gap-3">
        <div v-if="row.previews.length" class="flex -space-x-2">
          <img v-for="(src, i) in row.previews" :key="src + i" :src="src" :title="row.previewTitles[i] || ''" class="h-12 w-12 rounded object-cover border border-border bg-muted" alt="" />
        </div>
        <div class="min-w-0 flex-1 space-y-2">
          <div class="text-sm text-popover-foreground truncate">{{ row.label }}</div>
          <div class="flex items-center gap-2">
            <button class="rounded bg-primary px-2 py-0.5 text-[11px] text-primary-foreground" @click="onUndo(row.id)">Undo</button>
            <button class="rounded px-2 py-0.5 text-[11px] text-muted-foreground hover:bg-accent" @click="onDismiss(row.id)" aria-label="Dismiss">×</button>
          </div>
          <div class="h-1 w-full overflow-hidden rounded bg-muted">
            <div class="h-full bg-primary transition-[width] duration-50" :style="{ width: Math.max(0, 100 - Math.round((row.remainingMs / 10000) * 100)) + '%' }" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
