import mitt from 'mitt'

export type ReactionKind = 'love' | 'like' | 'dislike' | 'funny' | null

export type UndoAction = {
  id: string
  label: string
  expiresAt: number
  applyUI: () => void
  revertUI: () => void
  do: () => Promise<void>
  undo: () => Promise<void>
  previews?: string[]
  previewTitles?: string[]
  variant?: 'default' | 'destructive' | 'info'
}

type Events = {
  'undo:new': UndoAction
  'undo:update': { id: string, action: UndoAction }
  'undo:remove': { id: string }
}

class UndoManager {
  private actions: UndoAction[] = []
  private timers = new Map<string, number>()
  private pausedRemaining = new Map<string, number>()
  private graceMs = 10000
  public bus = mitt<Events>()

  configure(opts: { graceMs?: number, maxStack?: number } = {}) {
    if (opts.graceMs != null) this.graceMs = Math.max(0, opts.graceMs)
  }

  get stack() { return this.actions.slice() }
  get top(): UndoAction | undefined { return this.actions[0] }

  push(action: Omit<UndoAction, 'id' | 'expiresAt'> & { id?: string, expiresAt?: number }) {
    const id = action.id ?? crypto.randomUUID()
    const expiresAt = action.expiresAt ?? (Date.now() + this.graceMs)
    const full: UndoAction = { ...(action as any), id, expiresAt }

    // Apply optimistic UI immediately and commit immediately
    try { full.applyUI() } catch {}
    void full.do().catch(() => {/* ignore; commit failures are handled elsewhere if needed */})

    this.actions.unshift(full)
    this.bus.emit('undo:new', full)

    const ms = Math.max(0, expiresAt - Date.now())
    const t = window.setTimeout(() => this.expire(id), ms)
    this.timers.set(id, t)
  }

  async undo(id?: string) {
    const target = id ? this.actions.find(a => a.id === id) : this.actions[0]
    if (!target) return
    this.clearTimer(target.id)
    // Revert UI immediately
    try { target.revertUI() } catch {}
    // Fire server revert
    try { await target.undo() } catch {
      // On failure, best-effort: re-apply UI to committed state
      try { target.applyUI() } catch {}
    }
    this.remove(target.id)
  }

  expire(id: string) {
    const target = this.actions.find(a => a.id === id)
    if (!target) return
    this.clearTimer(id)
    // No-op for commit (already done at push())
    this.remove(id)
  }

  pause(id?: string) {
    const target = id ? this.actions.find(a => a.id === id) : this.actions[0]
    if (!target) return
    const remaining = Math.max(0, target.expiresAt - Date.now())
    this.clearTimer(target.id)
    this.pausedRemaining.set(target.id, remaining)
  }

  resume(id?: string) {
    const target = id ? this.actions.find(a => a.id === id) : this.actions[0]
    if (!target) return
    const remain = this.pausedRemaining.get(target.id)
    if (remain == null) return
    this.pausedRemaining.delete(target.id)
    target.expiresAt = Date.now() + remain
    const t = window.setTimeout(() => this.expire(target.id), remain)
    this.timers.set(target.id, t)
    this.bus.emit('undo:update', { id: target.id, action: target })
  }

  dismiss(id?: string) {
    const target = id ? this.actions.find(a => a.id === id) : this.actions[0]
    if (!target) return
    this.clearTimer(target.id)
    this.remove(target.id)
  }

  private clearTimer(id: string) {
    const t = this.timers.get(id)
    if (t != null) {
      window.clearTimeout(t)
      this.timers.delete(id)
    }
  }

  private remove(id: string) {
    this.actions = this.actions.filter(a => a.id !== id)
    this.bus.emit('undo:remove', { id })
  }
}

export const undoManager = new UndoManager()
