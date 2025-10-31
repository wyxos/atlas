import { bus } from '@/lib/bus'

const agg = {
  ids: new Set<number>(),
  previews: [] as string[],
  titles: [] as string[],
  timer: null as number | null,
}

export function enqueueModeration(ids: number[], previews: string[], titles: string[]) {
  for (const id of ids) agg.ids.add(id)
  for (const p of previews) {
    if (agg.previews.length >= 4) break
    if (!agg.previews.includes(p)) agg.previews.push(p)
  }
  for (const t of titles) {
    if (agg.titles.length >= 4) break
    if (!agg.titles.includes(t)) agg.titles.push(t)
  }
  scheduleFlushModeration()
}

export function flushModeration() {
  const ids = Array.from(agg.ids)
  if (ids.length === 0) return
  const previews = agg.previews.slice(0, 4)
  const previewTitles = agg.titles.slice(0, 4)
  agg.ids.clear()
  agg.previews = []
  agg.titles = []
  if (agg.timer != null) { clearTimeout(agg.timer); agg.timer = null }

  bus.emit('moderation:notify' as any, { ids, previews, previewTitles, count: ids.length })
}

function scheduleFlushModeration() {
  if (agg.timer != null) return
  agg.timer = window.setTimeout(() => {
    agg.timer = null
    flushModeration()
  }, 300)
}