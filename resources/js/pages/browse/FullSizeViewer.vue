<script setup lang="ts">
import * as BrowseController from '@/actions/App/Http/Controllers/BrowseController'
import FileReactions from '@/components/audio/FileReactions.vue'
import LoaderOverlay from '@/components/ui/LoaderOverlay.vue'
import ActionMenu, { type ActionOption } from '@/components/browse/ActionMenu.vue'
import { bus } from '@/lib/bus'
import axios from 'axios'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import { ChevronsLeft, ChevronsRight, Eye, X, AlertTriangle, ImageOff, SquareArrowOutUpRight } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ringForSlot, badgeClassForSlot } from '@/pages/browse/highlight'
import { highlightPromptHtml } from '@/utils/moderationHighlight'

const props = withDefaults(defineProps<{
  open: boolean
  item: any | null
  items: any[]
  scroller: any
  refreshOnEmpty?: boolean
}>(), {
  refreshOnEmpty: false
})

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'update:item', v: any | null): void
  (e: 'favorite', file: any, ev: Event): void
  (e: 'like', file: any, ev: Event): void
  (e: 'dislike', file: any, ev: Event): void
  (e: 'laughed-at', file: any, ev: Event): void
}>()

const dialogOpen = computed({ get: () => props.open, set: (v: boolean) => emit('update:open', v) })
const dialogItem = computed({ get: () => props.item, set: (v: any | null) => emit('update:item', v) })

const items = computed(() => props.items)
const scroller = computed(() => props.scroller)

const fullLoaded = ref(false)
const fullError = ref(false)
const fullRetryCount = ref(0)
const fullErrorKind = ref<'none' | 'not-found' | 'unavailable'>('none')
const fullErrorStatus = ref<number | null>(null)
const fullErrorMessage = ref<string | null>(null)
const fullVerifiedAvailableOnce = ref(false)

function setFullErrorState(
  kind: 'none' | 'not-found' | 'unavailable',
  status: number | null = null,
  message: string | null = null,
  syncItem = true,
) {
  fullErrorKind.value = kind
  fullErrorStatus.value = status
  fullErrorMessage.value = message
  fullError.value = kind !== 'none'

  const item = dialogItem.value as any
  if (!item || !syncItem) {
    return
  }

  if (kind === 'not-found') {
    item.not_found = true
  } else {
    item.not_found = false
  }

  if (kind === 'none') {
    delete item.media_error
  } else {
    item.media_error = { kind, status, message }
  }
}

const fullIsNotFoundError = computed(() => (dialogItem.value as any)?.not_found === true || fullErrorKind.value === 'not-found')
const isFileMarkedNotFound = computed(() => (dialogItem.value as any)?.not_found === true)
const showFullErrorOverlay = computed(() => fullIsNotFoundError.value || fullErrorKind.value === 'unavailable')
const fullOverlayIcon = computed(() => (fullIsNotFoundError.value ? ImageOff : AlertTriangle))
const fullOverlayMessage = computed(() => (fullIsNotFoundError.value ? 'Not found' : 'Unable to load media'))
const fullOverlayDetails = computed(() => {
  if (fullIsNotFoundError.value) {
      return null
  }
  if (fullErrorMessage.value) {
      return fullErrorMessage.value
  }
  if (fullErrorStatus.value != null) {
      return `HTTP ${fullErrorStatus.value}`
  }
  return null
})

watch(dialogItem, (item) => {
  fullVerifiedAvailableOnce.value = false

  if (!item) {
    setFullErrorState('none', null, null, false)
    return
  }

  if (item.media_error) {
    const mediaError = item.media_error as { kind?: string; status?: number | null; message?: string | null }
    const kind = (mediaError?.kind as 'none' | 'not-found' | 'unavailable') ?? 'unavailable'
    const status = mediaError?.status ?? null
    const message = mediaError?.message ?? null
    setFullErrorState(kind, status, message, false)
    return
  }

  if (item.not_found) {
    setFullErrorState('not-found', fullErrorStatus.value ?? 404, null, false)
    return
  }

  setFullErrorState('none', null, null, false)
})

watch(
  () => (dialogItem.value as any)?.media_error,
  (mediaError) => {
    if (!dialogItem.value) {
      return
    }

    if (!mediaError) {
      if (!(dialogItem.value as any).not_found) {
        setFullErrorState('none', null, null, false)
      }
      return
    }

    const kind = (mediaError?.kind as 'none' | 'not-found' | 'unavailable') ?? 'unavailable'
    const status = mediaError?.status ?? null
    const message = mediaError?.message ?? null

    if (fullErrorKind.value === kind && fullErrorStatus.value === status && fullErrorMessage.value === message) {
      return
    }

    setFullErrorState(kind, status, message, false)
  },
  { deep: true }
)

// ---------- Full-size action menu + batch highlight state ----------
const fullActionOpen = ref(false)
const fullActionInitialPathLabels = ref<string[] | null>(null)
const fullActionAnchorX = ref<number | null>(null)
const fullActionAnchorY = ref<number | null>(null)
const fullOverlayRef = ref<HTMLElement | null>(null)

function computeFullAnchorFromEvent(event?: Event) {
  const mouseEvent = event as MouseEvent | undefined
  if (mouseEvent && typeof mouseEvent.clientX === 'number' && typeof mouseEvent.clientY === 'number') {
    fullActionAnchorX.value = mouseEvent.clientX
    fullActionAnchorY.value = mouseEvent.clientY
    return
  }
  fullActionAnchorX.value = Math.round(window.innerWidth / 2)
  fullActionAnchorY.value = Math.round(window.innerHeight / 2)
}

const fullActionStyle = computed<Record<string, string>>(() => {
  if (fullActionAnchorX.value == null || fullActionAnchorY.value == null) return { display: 'none' } as Record<string, string>
  return { position: 'fixed', left: `${fullActionAnchorX.value}px`, top: `${fullActionAnchorY.value}px` } as Record<string, string>
})

let fullOutsideHandler: ((e: MouseEvent) => void) | null = null

// ---------- Container/Batch highlighting (shared with list view semantics) ----------
function listItemContainers(item: any): Array<{ key: string; value: string | number; label: string }> {
  const containersRaw = (((item?.containers || []) as any[]) || []).filter(Boolean)
  const formatted: Array<{ key: string; value: string | number; label: string }> = []
  for (const container of containersRaw) {
    const containerKey = String(container?.key ?? '')
    if (!containerKey) continue
    const containerValue = container?.value as string | number | null | undefined
    if (containerValue == null) continue
    const label = (container?.label as string | null | undefined) || containerKey
    formatted.push({ key: containerKey, value: containerValue as any, label })
  }
  return formatted
}

function computeContainerEntriesFor(item: any) {
  if (!item) return [] as Array<{ key: string; label: string; value: string | number; count: number; slotIndex: number }>
  const itemContainers = listItemContainers(item)
  const allItems = (items.value || []) as any[]
  const entries: Array<{ key: string; label: string; value: string | number; count: number; slotIndex: number }> = []
  for (let index = 0; index < itemContainers.length; index++) {
    const container = itemContainers[index]
    const countAll = allItems.filter((candidate) => ((candidate?.containers || []) as any[]).some((x: any) => x?.key === container.key && x?.value == container.value)).length
    if (countAll > 1) entries.push({ key: container.key, label: container.label, value: container.value, count: countAll, slotIndex: index })
  }
  return entries
}

const highlightTargets = ref<{
  sourceId?: number
  entries: Array<{ key: string; label: string; value: string | number; count: number; slotIndex: number }>
} | null>(null)
const highlightActive = computed(
  () => !!highlightTargets.value && Array.isArray(highlightTargets.value.entries) && highlightTargets.value.entries.length > 0,
)

function isThumbSibling(item: any): boolean {
  if (!highlightActive.value) return false
  const itemContainers = listItemContainers(item)
  const entries = highlightTargets.value?.entries || []
  return entries.some((entry) => itemContainers.some((container) => container.key === entry.key && container.value == entry.value))
}

function ringClassForItem(item: any): string {
  if (!highlightActive.value || !isThumbSibling(item)) return ''
  const itemContainers = listItemContainers(item)
  const entries = highlightTargets.value?.entries || []
  let chosenSlotIndex: number | null = null
  for (let i = 0; i < entries.length; i++) {
    const entry = entries[i]
    if (itemContainers.some((container) => container.key === entry.key && container.value == entry.value)) {
      if (chosenSlotIndex == null || entry.slotIndex < chosenSlotIndex) chosenSlotIndex = entry.slotIndex
    }
  }
  return chosenSlotIndex != null ? ringForSlot(chosenSlotIndex) : ''
}

function broadcastHighlightForDialogItem() {
  const id = dialogItem.value?.id
  if (!id) return
  const entries = computeContainerEntriesFor(dialogItem.value)
  if (!entries.length) return
  bus.emit('browse:highlight-containers', { sourceId: id, entries })
}
function clearHighlight() {
  bus.emit('browse:clear-highlight')
}

bus.on('browse:highlight-containers', (payload) => {
  highlightTargets.value = payload || null
})

bus.on('browse:clear-highlight', () => {
  highlightTargets.value = null
})

// Batch helpers in full menu
import { createBatchReact, type BatchAction, type BatchScope } from '@/pages/browse/useBatchReact'
const batchReact = createBatchReact({
  items: items as any,
  scroller: scroller as any,
  dialogOpen: dialogOpen as any,
  dialogItem: dialogItem as any,
  refreshOnEmpty: props.refreshOnEmpty
})

function containerScopesFor(item: any): { label: string; scope: BatchScope; badge?: { text: string; class: string } }[] {
  const uniqueKeyMap: Record<string, boolean> = {}
  const itemContainers = listItemContainers(item)
  const allItems = (items.value || []) as any[]
  return itemContainers
    .filter((container) => {
      const keyString = `${container.key}:${container.value}`
      if (uniqueKeyMap[keyString]) return false
      uniqueKeyMap[keyString] = true
      return true
    })
    .map((container, index) => {
      const countAll = allItems.filter((candidate) => ((candidate?.containers || []) as any[]).some((x: any) => x?.key === container.key && x?.value == container.value)).length
      return { label: container.label, scope: { key: container.key, value: container.value }, badge: { text: String(countAll), class: badgeClassForSlot(index) } }
    })
}

// URL computed properties (matching GridItem)
const thumbnailUrl = computed<string | null>(() => {
  const item = dialogItem.value as any
  if (!item) return null
  const preview = typeof item?.preview === 'string' ? item.preview.trim() : ''
  if (preview) return preview
  const fallback = typeof item?.thumbnail_url === 'string' ? item.thumbnail_url.trim() : ''
  return fallback || null
})

const originalUrl = computed<string | null>(() => {
  const item = dialogItem.value as any
  if (!item) return null
  const original = typeof item?.original === 'string' ? item.original.trim() : ''
  return original || null
})

const trueOriginalUrl = computed<string | null>(() => {
  const raw = (dialogItem.value as any)?.true_original_url
  if (typeof raw !== 'string') return null
  const trimmed = raw.trim()
  return trimmed.length > 0 ? trimmed : null
})

const trueThumbnailUrl = computed<string | null>(() => {
  const raw = (dialogItem.value as any)?.true_thumbnail_url
  if (typeof raw !== 'string') return null
  const trimmed = raw.trim()
  return trimmed.length > 0 ? trimmed : null
})

const referrerUrl = computed<string | null>(() => {
  const raw = (dialogItem.value as any)?.referrer_url
  if (typeof raw !== 'string') return null
  const trimmed = raw.trim()
  return trimmed.length > 0 ? trimmed : null
})

// Copy and open URL functions (matching GridItem)
async function copyToClipboard(text: string): Promise<boolean> {
  if (!text) return false
  try {
    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {}
  if (typeof document === 'undefined') return false
  try {
    const textarea = document.createElement('textarea')
    textarea.value = text
    textarea.setAttribute('readonly', 'true')
    textarea.style.position = 'absolute'
    textarea.style.opacity = '0'
    document.body.appendChild(textarea)
    textarea.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(textarea)
    return ok
  } catch {}
  return false
}

function copyUrl(url: string | null): void {
  if (!url) return
  void copyToClipboard(url)
}

async function copyImageToClipboard(): Promise<void> {
  try {
    const imgElement = mediaWrapRef.value?.querySelector('img') as HTMLImageElement | null
    if (!imgElement || !imgElement.complete) return
    const canvas = document.createElement('canvas')
    canvas.width = imgElement.naturalWidth || imgElement.width
    canvas.height = imgElement.naturalHeight || imgElement.height
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    ctx.drawImage(imgElement, 0, 0)
    canvas.toBlob(async (blob) => {
      if (!blob) return
      try {
        if (navigator.clipboard && navigator.clipboard.write) {
          const clipboardItem = new ClipboardItem({ [blob.type]: blob })
          await navigator.clipboard.write([clipboardItem])
        }
      } catch {
        try {
          const dataUrl = canvas.toDataURL('image/png')
          await copyToClipboard(dataUrl)
        } catch {}
      }
    }, 'image/png')
  } catch {
    // Silently fail
  }
}

function openUrlInNewTab(url?: string | null): void {
  const raw = url || originalUrl.value || thumbnailUrl.value || ''
  if (!raw) return
  try {
    window.open(raw, '_blank', 'noopener,noreferrer')
  } catch {
    // ignore
  }
}

const fullMenuOptions = computed<ActionOption[]>(() => {
  const file = dialogItem.value as any
  const base: ActionOption[] = [
    {
      label: 'react',
      children: [
{ label: 'favorite', action: (event?: Event) => emit('favorite', file, (event || ({} as any)) as any) },
        { label: 'like', action: (event?: Event) => emit('like', file, (event || ({} as any)) as any) },
        { label: 'dislike', action: (event?: Event) => emit('dislike', file, (event || ({} as any)) as any) },
        { label: 'funny', action: (event?: Event) => emit('laughed-at', file, (event || ({} as any)) as any) },
      ],
    },
  ]

  // Copy URL menu items (matching GridItem)
  const copyChildren: ActionOption[] = []
  if (referrerUrl.value) {
    copyChildren.push({ label: 'copy referrer url', action: () => copyUrl(referrerUrl.value) })
  }
  if (trueOriginalUrl.value) {
    copyChildren.push({ label: 'copy original url', action: () => copyUrl(trueOriginalUrl.value) })
  }
  if (trueThumbnailUrl.value) {
    copyChildren.push({ label: 'copy original preview url', action: () => copyUrl(trueThumbnailUrl.value) })
  }
  if (thumbnailUrl.value) {
    copyChildren.push({ label: 'copy thumbnail url', action: () => copyUrl(thumbnailUrl.value) })
  }
  const currentUrl = originalUrl.value || thumbnailUrl.value
  if (currentUrl) {
    copyChildren.push({ label: 'copy url', action: () => copyUrl(currentUrl) })
  }
  if (fullShouldRenderVideo.value === false) {
    copyChildren.push({ label: 'copy image', action: () => copyImageToClipboard() })
  }
  if (copyChildren.length > 0) {
    base.push({ label: 'copy url', children: copyChildren })
  }

  // Open URL menu items (matching GridItem)
  const openChildren: ActionOption[] = []
  if (referrerUrl.value) {
    openChildren.push({ label: 'open referrer url', action: () => openUrlInNewTab(referrerUrl.value) })
  }
  if (trueOriginalUrl.value) {
    openChildren.push({ label: 'open original url', action: () => openUrlInNewTab(trueOriginalUrl.value) })
  }
  if (trueThumbnailUrl.value) {
    openChildren.push({ label: 'open original preview url', action: () => openUrlInNewTab(trueThumbnailUrl.value) })
  }
  if (thumbnailUrl.value) {
    openChildren.push({ label: 'open thumbnail url', action: () => openUrlInNewTab(thumbnailUrl.value) })
  }
  if (currentUrl) {
    openChildren.push({ label: 'open url', action: () => openUrlInNewTab(currentUrl) })
  }
  if (openChildren.length > 0) {
    base.push({ label: 'open url', children: openChildren })
  }

  // Batch menu items
  if (file) {
    const scopes = containerScopesFor(file)
    if (scopes.length > 0) {
      const scopeChildren = (action: BatchAction): ActionOption[] => scopes.map((s) => ({ label: s.label, badge: s.badge, action: () => batchReact(action, s.scope) }))
      base.push({
        label: 'batch',
        children: [
          { label: 'favorite', children: scopeChildren('favorite') },
          { label: 'like', children: scopeChildren('like') },
          { label: 'funny', children: scopeChildren('funny') },
          { label: 'dislike', children: scopeChildren('dislike') },
        ],
      })
    }
  }
  return base
})

function onFullMediaContextMenu(event: MouseEvent) {
  if (!dialogItem.value) return
  if (event.altKey) {
    event.preventDefault()
    event.stopPropagation()
    emit('dislike', dialogItem.value as any, event as any)
    return
  }
  event.preventDefault(); event.stopPropagation()
  fullActionInitialPathLabels.value = null
  computeFullAnchorFromEvent(event)
  fullActionOpen.value = true
}

function onFullMediaMouseDown(event: MouseEvent) {
  if (!dialogItem.value) return
  const delta = mouseBackForwardDelta(event)
  if (event.altKey && delta !== 0) {
    event.preventDefault(); event.stopPropagation()
    fullActionInitialPathLabels.value = ['batch', delta < 0 ? 'dislike' : 'like']
    computeFullAnchorFromEvent(event)
    const scopes = containerScopesFor(dialogItem.value)
    if (scopes.length > 0) broadcastHighlightForDialogItem()
    fullActionOpen.value = true
    return
  }
}

function onFullMediaMouseUp(event: MouseEvent) {
  if (event.altKey && (event.button === 3 || event.button === 4)) {
    event.preventDefault(); event.stopPropagation()
  }
}

function onFullMediaAuxClick(event: MouseEvent) {
  // Alt+back/forward: block navigation
  if (event.altKey && (event.button === 3 || event.button === 4)) {
    event.preventDefault(); event.stopPropagation()
    return
  }
  // Middle-click without Alt: open in new tab
  if (!event.altKey && event.button === 1) {
    event.preventDefault(); event.stopPropagation()
    openFullMediaInNewTab()
    return
  }
}

const fullMediaSrc = computed(() => {
  const item = dialogItem.value
  if (!item) return ''
  const raw = (item?.original as string | undefined) || (item?.preview as string | undefined) || ''
  if (!raw) return ''
  return raw
})

const fullResolvedMediaKind = computed<'video' | 'image'>(() => {
  const item = dialogItem.value as any
  if (!item) {
    return 'image'
  }

  return item?.type === 'video' ? 'video' : 'image'
})

const fullShouldRenderVideo = computed(() => fullResolvedMediaKind.value === 'video')

// Bottom thumbnail carousel state
const thumbsVisible = ref(false)
// All items for navigation in insertion order (row-major as thumbnails render)
const allThumbnails = computed(() => {
  const mapped = (items.value || [])
    .map((item, idx) => ({
      id: item?.id,
      preview: item?.preview || item?.thumbnail_url || null,
      item,
      originalIndex: (item as any)?.originalIndex ?? idx,
    }))
  return mapped.sort((a, b) => a.originalIndex - b.originalIndex)
})
// Filtered thumbnails for display in carousel (only items with previews)
const thumbnails = computed(() => allThumbnails.value.filter((thumb) => !!thumb.preview))
// Navigation must always follow the full, stable insertion order regardless of thumbnail slider visibility
const navList = computed(() => allThumbnails.value)
const loadedThumbs = reactive<Record<number, boolean>>({})
const THUMB_WINDOW = 11
const thumbStart = ref(0)
const visibleThumbs = computed(() => thumbnails.value.slice(thumbStart.value, Math.min(thumbnails.value.length, thumbStart.value + THUMB_WINDOW)))
// Active index in the ALL items array (for navigation)
const activeThumbIndex = computed(() => {
  const id = dialogItem.value?.id
  return allThumbnails.value.findIndex((thumb) => thumb.id === id)
})
// Active index in the VISIBLE thumbnails array (for carousel display)
const activeVisibleThumbIndex = computed(() => {
  const id = dialogItem.value?.id
  return thumbnails.value.findIndex((thumb) => thumb.id === id)
})

function clampStartForIndex(index: number): number {
  const maxStart = Math.max(0, thumbnails.value.length - THUMB_WINDOW)
  const half = Math.floor(THUMB_WINDOW / 2)
  const desired = Math.max(0, index - half)
  return Math.min(maxStart, desired)
}

function ensureActiveThumbInView() {
  // Use visible thumb index for carousel positioning
  const index = activeVisibleThumbIndex.value
  if (index < 0) return
  const endIndex = thumbStart.value + THUMB_WINDOW - 1
  if (index < thumbStart.value || index > endIndex) {
    thumbStart.value = clampStartForIndex(index)
  } else {
    const desired = clampStartForIndex(index)
    if (desired !== thumbStart.value) thumbStart.value = desired
  }
}

function toggleThumbs() {
  thumbsVisible.value = !thumbsVisible.value
  if (thumbsVisible.value) void nextTick().then(() => ensureActiveThumbInView())
}

function onMediaClick(event: MouseEvent) {
  if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return
  toggleThumbs()
}

function onThumbClick(item: any) {
  if (!item) return
  fullLoaded.value = false
  dialogItem.value = item
  void nextTick().then(() => ensureActiveThumbInView())
}

function thumbPrev() {
  // Use all items for consistent navigation order
  const id = dialogItem.value?.id
  const index = navList.value.findIndex((t) => t.id === id)
  if (index <= 0) return
  const thumb = navList.value[index - 1]
  if (thumb) onThumbClick(thumb.item)
}
async function thumbNext() {
  // Use all items for consistent navigation order
  const id = dialogItem.value?.id
  const index = navList.value.findIndex((t) => t.id === id)
  if (index < 0) return
  if (index < navList.value.length - 1) {
    const thumb = navList.value[index + 1]
    if (thumb) onThumbClick(thumb.item)
    return
  }
  try {
    if (scroller.value?.loadNext) {
      await scroller.value.loadNext(); await nextTick()
    }
  } catch {}
  const nextIndex = index + 1
  const thumb = navList.value[nextIndex] ?? null
  if (thumb) onThumbClick(thumb.item)
}

const fullSeenIds = new Set<number>()
async function reportFileSeen(item: any) {
  const id = item?.id
  if (!id || fullSeenIds.has(id)) return
  fullSeenIds.add(id)
  try {
    const action = (BrowseController as any).fileSeen({ file: id })
    if (action?.url) {
      const response = await axios.post(action.url)
      const seenCount = (response?.data?.seen_count ?? null) as number | null
      if (typeof seenCount === 'number') {
        (item as any).seen_count = seenCount
      } else {
        (item as any).seen_count = ((item as any).seen_count ?? 0) + 1
      }
    }
  } catch {}
}

function onFullImageLoad() {
  fullLoaded.value = true
  fullVerifiedAvailableOnce.value = false
  setFullErrorState('none', null, null, false)
  if (dialogItem.value) void reportFileSeen(dialogItem.value)
}
function onFullVideoCanPlay() {
  fullLoaded.value = true
  fullVerifiedAvailableOnce.value = false
  setFullErrorState('none', null, null, false)
}
function onFullVideoTimeUpdate(event: Event) {
  const videoElement = event?.target as HTMLVideoElement | null
  const duration = videoElement?.duration ?? 0
  const currentTime = videoElement?.currentTime ?? 0
  if (!duration || !isFinite(duration)) return
  const threshold = Math.max(0, duration - Math.min(0.25, duration * 0.05))
  if (currentTime >= threshold && dialogItem.value) void reportFileSeen(dialogItem.value)
}

function onFullImageError() {
  handleFullMediaError()
}

function onFullVideoError() {
  handleFullMediaError()
}

function retryFullMedia(fromUser = false) {
  if (!dialogItem.value) return
  if (fromUser) {
    fullVerifiedAvailableOnce.value = false
  }
  fullRetryCount.value += 1
  fullLoaded.value = false
  setFullErrorState('none')
}

function openFullMediaInNewTab() {
  openUrlInNewTab()
}

async function clearNotFoundFlag() {
  const id = dialogItem.value?.id as number | undefined
  if (!id) return

  try {
    const action = (BrowseController as any).clearNotFound({ file: id })
    if (!action?.url) return

    await axios.post(action.url)

    // Update local state
    if (dialogItem.value) {
      (dialogItem.value as any).not_found = false
      setFullErrorState('none', null, null, false)
    }
  } catch (error) {
    // Silently fail
  }
}

async function handleFullMediaError() {
  fullLoaded.value = true

  const id = dialogItem.value?.id as number | undefined
  if (!id) {
    setFullErrorState('unavailable', null, 'Unable to load media', false)
    return
  }

  try {
    const action = (BrowseController as any).reportMissing({ file: id })
    if (!action?.url) {
      setFullErrorState('unavailable', null, 'Unable to load media')
      return
    }

    const response = await axios.post(action.url, { verify: true })
    const statusRaw = response?.data?.status
    const status = typeof statusRaw === 'number' ? Number(statusRaw) : null
    const confirmedMissing = response?.data?.not_found === true
    const serverMessage = (response?.data?.message ?? null) as string | null

    if (confirmedMissing) {
      fullVerifiedAvailableOnce.value = false
      setFullErrorState('not-found', status ?? 404, null)
      return
    }

    if (status && status >= 400) {
      const fallbackMessage = serverMessage || `Remote server responded with ${status}`
      setFullErrorState('unavailable', status, fallbackMessage)
      return
    }

    if ((status === 200 || response?.data?.verified === true)) {
      if (!fullVerifiedAvailableOnce.value) {
        fullVerifiedAvailableOnce.value = true
        setFullErrorState('none')
        retryFullMedia()
        return
      }

      const message = serverMessage || 'Unable to load media after retry'
      setFullErrorState('unavailable', status ?? null, message)
      return
    }

    const fallback = serverMessage || 'Unable to load media'
    setFullErrorState('unavailable', status ?? null, fallback)
  } catch (error: any) {
    const status = error?.response?.status as number | undefined
    if (status === 404) {
    fullVerifiedAvailableOnce.value = false
      setFullErrorState('not-found', 404, null)
    } else {
      const message =
        error?.response?.data?.message ||
        error?.message ||
        (status ? `Remote server responded with ${status}` : 'Unable to load media')
      setFullErrorState('unavailable', status ?? null, message)
    }
  }
}

async function navigate(delta: number) {
  fullLoaded.value = false
  if (!dialogItem.value) return
  // Always use all items for consistent navigation regardless of slider state
  const currentId = dialogItem.value?.id
  const index = navList.value.findIndex((thumb) => thumb.id === currentId)
  const targetIndex = index + delta

  console.log('[FullSizeViewer] navigate:', {
    delta,
    currentId,
    currentIndex: index,
    targetIndex,
    allThumbnailsLength: allThumbnails.value.length,
    surrounding: allThumbnails.value.slice(Math.max(0, index - 1), Math.min(allThumbnails.value.length, index + 4)).map(t => ({ id: t.id, index: allThumbnails.value.indexOf(t) })),
    targetItemId: allThumbnails.value[targetIndex]?.id,
    itemsLength: items.value.length
  })

  if (index < 0) return
  if (targetIndex < 0) return
  if (targetIndex < navList.value.length) {
    const thumb = navList.value[targetIndex]
    if (thumb) {
      fullLoaded.value = false
      console.log('[FullSizeViewer] Setting dialogItem to:', thumb.id)
      dialogItem.value = thumb.item
    }
    return
  }
  // Need to load next page
  if (delta > 0) {
    try {
      if (scroller.value?.loadNext) {
        await scroller.value.loadNext()
        await nextTick()
      }
    } catch {}
    const thumb = navList.value[targetIndex]
    if (thumb) {
      fullLoaded.value = false
      dialogItem.value = thumb.item
    }
  }
}

function onDialogMouseUp(event: MouseEvent) {
  if (!dialogItem.value) return
  const delta = mouseBackForwardDelta(event)
  if (delta !== 0) {
    if (event.altKey || fullActionOpen.value) { event.preventDefault(); event.stopPropagation(); return }
    event.preventDefault(); event.stopPropagation(); withNavLock(() => void navigate(delta)); return
  }
  if (!event.altKey) return
  if (event.button === 0) { event.preventDefault(); event.stopPropagation(); emit('like', dialogItem.value as any, event as any) }
  else if (event.button === 1) { event.preventDefault(); event.stopPropagation(); emit('favorite', dialogItem.value as any, event as any) }
}

function mouseBackForwardDelta(event: MouseEvent): number {
  const button = (event as any).button
  if (button === 3) return -1
  if (button === 4) return 1
  const mask = (event as any).buttons as number | undefined
  if (typeof mask === 'number') {
    if ((mask & 8) === 8) return -1
    if ((mask & 16) === 16) return 1
  }
  return 0
}

// Keep thumb window valid if the item list changes
watch(() => thumbnails.value, () => {
  const maxStart = Math.max(0, thumbnails.value.length - THUMB_WINDOW)
  if (thumbStart.value > maxStart) thumbStart.value = maxStart
  ensureActiveThumbInView()
})

watch(dialogOpen, (isOpen) => { if (isOpen) void nextTick().then(() => { const element = mediaWrapRef.value as HTMLElement | null; if (element) { try { element.focus({ preventScroll: true } as any) } catch { try { element.focus() } catch {} } } }) })

// Guard against duplicate navigation firing from multiple mouse events (mouseup + auxclick)
let navLock = false
function withNavLock(action: () => void | Promise<void>) {
  if (navLock) return
  navLock = true
  const release = () => { navLock = false }
  const done = () => setTimeout(release, 120)
  try {
    const res = action()
    if (res && typeof (res as any).then === 'function') {
      ;(res as Promise<void>).finally(done)
    } else {
      done()
    }
  } catch {
    done()
  }
}

onMounted(() => {
  const onOutside = (event: MouseEvent) => {
    if (!fullActionOpen.value) return
    const overlay = fullOverlayRef.value
    if (overlay && event.target instanceof Node && overlay.contains(event.target)) return
    fullActionOpen.value = false
    clearHighlight()
  }
  window.addEventListener('mousedown', onOutside as any, { capture: true })
  window.addEventListener('contextmenu', onOutside as any, { capture: true })
  fullOutsideHandler = onOutside

  window.addEventListener('keydown', onKeyDownHandler as any, { passive: false })
  window.addEventListener('mousedown', onMouseDownHandler as any, { passive: false, capture: true })
  window.addEventListener('mouseup', onMouseUpHandler as any, { passive: false, capture: true })
  window.addEventListener('auxclick', onAuxClickHandler as any, { passive: false, capture: true })
})

onBeforeUnmount(() => {
  if (fullOutsideHandler) {
    window.removeEventListener('mousedown', fullOutsideHandler as any, true as any)
    window.removeEventListener('contextmenu', fullOutsideHandler as any, true as any)
    fullOutsideHandler = null
  }
  window.removeEventListener('keydown', onKeyDownHandler as any)
  window.removeEventListener('mousedown', onMouseDownHandler as any, true as any)
  window.removeEventListener('mouseup', onMouseUpHandler as any, true as any)
  window.removeEventListener('auxclick', onAuxClickHandler as any, true as any)
})

// Global keyboard navigation while full-size dialog is open
const onKeyDownHandler = async (event: KeyboardEvent) => {
  if (!dialogOpen.value) return
  const activeTag = (document.activeElement?.tagName || '').toLowerCase()
  if (activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select' || (document.activeElement as any)?.isContentEditable) return

  if (event.altKey && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
    event.preventDefault(); event.stopPropagation()
    fullActionInitialPathLabels.value = ['batch', event.key === 'ArrowLeft' ? 'dislike' : 'like']
    computeFullAnchorFromEvent()
    if (dialogItem.value && containerScopesFor(dialogItem.value).length > 0) broadcastHighlightForDialogItem()
    fullActionOpen.value = true
    return
  }

  if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); dialogOpen.value = false; dialogItem.value = null; return }
  if (event.key === 'ArrowLeft') { event.preventDefault(); event.stopPropagation(); await navigate(-1); return }
  if (event.key === 'ArrowRight') { event.preventDefault(); event.stopPropagation(); await navigate(1); return }
}

// Intercept mouse back/forward globally so browser history doesn't fire
const onMouseDownHandler = (event: MouseEvent) => {
  if (!dialogOpen.value) return
  const delta = mouseBackForwardDelta(event)
  if (delta !== 0) {
    event.preventDefault()
  }
}
const onMouseUpHandler = (event: MouseEvent) => {
  if (!dialogOpen.value) return
  const delta = mouseBackForwardDelta(event)
  if (delta !== 0) {
    if (event.altKey || fullActionOpen.value) { event.preventDefault(); event.stopPropagation(); return }
    event.preventDefault(); event.stopPropagation(); withNavLock(() => void navigate(delta))
  }
}
const onAuxClickHandler = (event: MouseEvent) => {
  if (!dialogOpen.value) return
  const delta = mouseBackForwardDelta(event)
  if (delta !== 0) {
    if (event.altKey || fullActionOpen.value) { event.preventDefault(); event.stopPropagation(); return }
    event.preventDefault(); event.stopPropagation(); withNavLock(() => void navigate(delta))
  }
}

watch(dialogItem, () => {
  fullLoaded.value = false
  fullRetryCount.value = 0
  if (dialogOpen.value && thumbsVisible.value) void nextTick().then(() => ensureActiveThumbInView())
})

watch(dialogOpen, (isOpen) => {
  if (isOpen) {
    fullLoaded.value = false
    fullRetryCount.value = 0
  }
})

watch(thumbsVisible, (visible) => { if (visible) void nextTick().then(() => ensureActiveThumbInView()) })
const mediaWrapRef = ref<HTMLElement | null>(null)

// Compute moderation info and highlighted prompt for debugging
const moderationInfo = computed(() => {
  const meta = (dialogItem.value as any)?.metadata ?? {}
  const m = (meta as any)?.moderation ?? null
  if (!m || (m as any)?.reason !== 'moderation:rule') return null
  return m as { reason: string; rule_id?: number; rule_name?: string | null; options?: { case_sensitive?: boolean; whole_word?: boolean } | null; hits?: string[] }
})

// Get blacklist reason from file metadata or default
const blacklistReason = computed(() => {
  return (dialogItem.value as any)?.blacklist_reason ?? null
})

const isBlacklisted = computed(() => {
  return !!(dialogItem.value as any)?.blacklisted_at
})

const moderationHits = computed<string[]>(() => {
  const arr = (moderationInfo.value?.hits || []) as string[]
  return Array.isArray(arr) ? arr.filter((s) => typeof s === 'string' && s.trim().length > 0) : []
})

const highlightedPromptHtml = computed(() => {
  const raw = String(((dialogItem.value as any)?.metadata?.prompt ?? '') || '')
  if (!raw) return ''
  const terms = moderationHits.value
  if (!terms.length) return highlightPromptHtml(raw, [], {})
  const options = (moderationInfo.value?.options ?? {}) as { case_sensitive?: boolean; whole_word?: boolean }
  return highlightPromptHtml(raw, terms, options)
})
</script>

<template>
  <Dialog v-model:open="dialogOpen">
    <DialogContent class="h-screen max-h-none w-screen !max-w-full p-0">
      <DialogTitle class="sr-only">Media viewer</DialogTitle>
      <DialogDescription class="sr-only">Full size preview</DialogDescription>
      <div class="flex h-screen w-screen flex-col bg-background">
        <div class="flex flex-1 overflow-hidden">
          <!-- Left column: media + slider underneath -->
          <div class="flex flex-1 flex-col overflow-hidden">
            <div class="relative flex flex-1 place-items-center overflow-hidden p-2" @mouseup.capture="onDialogMouseUp">
              <div class="absolute inset-0 z-10 grid place-items-center transition-opacity duration-300" :class="fullLoaded ? 'pointer-events-none opacity-0' : 'opacity-100'">
                <LoaderOverlay />
              </div>
              <div v-if="showFullErrorOverlay" class="absolute inset-0 z-20 grid place-items-center bg-background/70 p-6 text-center">
                <div class="flex flex-col items-center gap-3">
                  <component :is="fullOverlayIcon" :size="44" class="text-destructive" />
                  <div class="flex flex-col items-center gap-1">
                    <span class="text-sm font-semibold text-destructive">{{ fullOverlayMessage }}</span>
                    <span v-if="fullOverlayDetails" class="max-w-[260px] text-xs font-normal text-muted-foreground">{{ fullOverlayDetails }}</span>
                  </div>
                  <div class="flex gap-2">
                    <Button variant="outline" class="h-8 px-3 text-xs" @click.stop="retryFullMedia(true)">Retry</Button>
                    <Button variant="outline" class="h-8 px-3 text-xs" @click.stop="openFullMediaInNewTab">Open</Button>
                    <Button v-if="isFileMarkedNotFound" variant="default" class="h-8 px-3 text-xs" @click.stop="clearNotFoundFlag">Clear Not Found</Button>
                  </div>
                </div>
              </div>
              <!-- Close button positioned relative to media container -->
              <button class="absolute top-3 right-3 z-20 rounded-full bg-background/80 p-1 shadow ring-1 ring-border hover:bg-background"
                      @click.stop="((dialogOpen = false), (dialogItem = null), (fullActionOpen = false), clearHighlight())"
                      aria-label="Close">
                <X :size="18" />
              </button>
<div ref="mediaWrapRef" tabindex="-1" class="flex h-full flex-1 items-center justify-center focus:outline-none">
                <template v-if="fullShouldRenderVideo">
                  <video v-if="dialogItem?.original || dialogItem?.preview"
                         :key="`${dialogItem?.id || 'video'}:${fullRetryCount}`"
                         :src="fullMediaSrc"
                         referrerpolicy="no-referrer"
                         class="max-h-full max-w-full object-contain transition-opacity duration-300 select-none"
                         :class="[fullLoaded ? 'opacity-100' : 'opacity-0']"
                         autoplay loop muted playsinline preload="metadata"
                         @click="onMediaClick" @canplay="onFullVideoCanPlay" @timeupdate="onFullVideoTimeUpdate" @error="onFullVideoError"
                         @mousedown.capture="onFullMediaMouseDown" @mouseup.capture="onFullMediaMouseUp" @auxclick.capture="onFullMediaAuxClick"
                         @contextmenu="onFullMediaContextMenu"></video>
                </template>
                <template v-else>
                  <img v-if="dialogItem?.original || dialogItem?.preview"
                       :key="`${dialogItem?.id || 'image'}:${fullRetryCount}`"
                       :src="fullMediaSrc"
                       referrerpolicy="no-referrer"
                       class="max-h-full max-w-full object-contain transition-opacity duration-300 select-none"
                       :class="[fullLoaded ? 'opacity-100' : 'opacity-0']" alt="Full size" draggable="false"
                       @click="onMediaClick" @load="onFullImageLoad" @error="onFullImageError"
                       @mousedown.capture="onFullMediaMouseDown" @mouseup.capture="onFullMediaMouseUp" @auxclick.capture="onFullMediaAuxClick"
                       @contextmenu="onFullMediaContextMenu" />
                </template>
              </div>
            </div>
            <!-- Thumbnail carousel slider underneath -->
            <div v-if="thumbsVisible" class="border-t px-4 py-3">
              <div class="relative mx-auto max-w-[90%]">
                <!-- nav buttons -->
                <button class="absolute top-1/2 left-2 z-10 -translate-y-1/2 rounded-full bg-background/90 p-1 shadow ring-1 ring-border hover:bg-background disabled:opacity-50"
                        :disabled="activeThumbIndex <= 0" @click.stop="thumbPrev" aria-label="Previous">
                  <ChevronsLeft :size="20" />
                </button>
                <button class="absolute top-1/2 right-2 z-10 -translate-y-1/2 rounded-full bg-background/90 p-1 shadow ring-1 ring-border hover:bg-background disabled:opacity-50"
                        :disabled="false" @click.stop="thumbNext" aria-label="Next">
                  <ChevronsRight :size="20" />
                </button>

                <!-- Fixed-size slider window -->
                <div class="flex items-center justify-center gap-3 overflow-hidden">
<div v-for="thumb in visibleThumbs" :key="thumb.id" class="shrink-0 p-[3px]" :data-thumb-id="thumb.id" @click.stop="onThumbClick(thumb.item)" style="width: 146px; height: 146px">
                    <button class="relative h-full w-full rounded border transition-transform hover:scale-[1.02]"
                            :class="[
                              highlightActive && ringClassForItem(thumb.item) ? ringClassForItem(thumb.item)
                              : dialogItem?.id === thumb.id ? 'ring-2 ring-primary ring-offset-1 ring-offset-background' : '',
                            ]">
                      <div class="absolute inset-0 grid place-items-center transition-opacity duration-300" :class="loadedThumbs[thumb.id] ? 'pointer-events-none opacity-0' : 'opacity-100'">
                        <LoaderOverlay />
                      </div>
                      <img :src="thumb.preview" alt="" class="h-full w-full rounded object-contain" @load="loadedThumbs[thumb.id] = true" />
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!-- Right panel -->
          <div v-if="dialogItem" class="w-1/3 overflow-x-hidden overflow-y-auto border-l bg-muted/40 p-8 dark:bg-neutral-900/60">
              <p class="mb-4">ID: {{ dialogItem.id}}</p>
            <!-- Blacklist Status (for all blacklisted items) -->
            <div v-if="isBlacklisted && !moderationInfo" class="mb-4 rounded-lg border border-red-500/50 bg-red-50/50 p-3 dark:bg-red-900/10">
              <div class="flex items-start gap-2">
                <AlertTriangle :size="18" class="mt-0.5 shrink-0 text-red-600 dark:text-red-400" />
                <div class="flex-1">
                  <h3 class="text-xs font-semibold text-red-900 dark:text-red-100">Blacklisted</h3>
                  <p class="mt-0.5 text-xs text-red-700 dark:text-red-300">
                    Reason: <span class="font-mono">{{ blacklistReason || 'unknown' }}</span>
                  </p>
                  <p v-if="blacklistReason !== 'moderation:rule'" class="mt-2 text-xs italic text-red-600/80 dark:text-red-400/80">
                    No moderation metadata available. This item was likely blacklisted manually or through a different flow.
                  </p>
                </div>
              </div>
            </div>

            <!-- Moderation Info (if auto-blacklisted by rules) -->
            <div v-if="moderationInfo" class="mb-6 rounded-lg border border-amber-500/50 bg-amber-50/50 p-4 dark:bg-amber-900/10">
              <div class="mb-3 flex items-start gap-2">
                <AlertTriangle :size="20" class="mt-0.5 shrink-0 text-amber-600 dark:text-amber-400" />
                <div class="flex-1">
                  <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-100">Auto-Blacklisted</h3>
                  <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                    {{ moderationInfo.rule_name || `Rule #${moderationInfo.rule_id}` }}
                  </p>
                </div>
              </div>

              <!-- Rule Options -->
              <div class="mb-3 space-y-1 text-xs">
                <div class="flex items-center gap-2">
                  <span class="font-medium text-amber-800 dark:text-amber-200">Whole Word:</span>
                  <span class="text-amber-700 dark:text-amber-300">
                    {{ moderationInfo.options?.whole_word ?? true ? 'Yes' : 'No' }}
                  </span>
                </div>
                <div class="flex items-center gap-2">
                  <span class="font-medium text-amber-800 dark:text-amber-200">Case Sensitive:</span>
                  <span class="text-amber-700 dark:text-amber-300">
                    {{ moderationInfo.options?.case_sensitive ?? false ? 'Yes' : 'No' }}
                  </span>
                </div>
              </div>

              <!-- Matched Terms -->
              <div v-if="moderationHits.length" class="mb-3">
                <div class="mb-2 text-xs font-medium text-amber-800 dark:text-amber-200">Matched Terms:</div>
                <div class="flex flex-wrap gap-1.5">
                  <span v-for="term in moderationHits" :key="term"
                        class="rounded bg-amber-200/60 px-2 py-0.5 text-xs font-mono text-amber-900 dark:bg-amber-800/40 dark:text-amber-100">
                    {{ term }}
                  </span>
                </div>
              </div>

              <!-- Highlighted Prompt -->
              <div v-if="highlightedPromptHtml" class="mt-3">
                <div class="mb-2 text-xs font-medium text-amber-800 dark:text-amber-200">Highlighted Prompt:</div>
                <div class="max-h-40 overflow-y-auto rounded bg-white/50 p-3 text-xs leading-relaxed dark:bg-black/20"
                     v-html="highlightedPromptHtml"></div>
              </div>
            </div>

            <!-- Full Prompt (if not moderated) -->
            <div v-else-if="dialogItem?.metadata?.prompt" class="mb-4">
              <h3 class="mb-2 text-sm font-semibold">Prompt</h3>
              <p class="text-xs leading-relaxed text-muted-foreground">{{ dialogItem?.metadata?.prompt }}</p>
            </div>

            <!-- Listing Metadata -->
            <div v-if="dialogItem?.listing_metadata && Object.keys(dialogItem.listing_metadata).length > 0" class="mb-4">
              <h3 class="mb-2 text-sm font-semibold">Listing Metadata</h3>
              <details>
                <summary class="cursor-pointer text-xs text-muted-foreground">Show listing metadata</summary>
                <div class="mt-2 overflow-auto max-h-60 rounded bg-muted/50 p-3">
                  <pre class="text-xs">{{ JSON.stringify(dialogItem.listing_metadata, null, 2) }}</pre>
                </div>
              </details>
            </div>

            <!-- Disk Absolute Path -->
            <div v-if="dialogItem?.absolute_path" class="mb-4">
              <h3 class="mb-2 text-sm font-semibold">Disk Absolute Path</h3>
              <p class="break-all rounded bg-muted/50 p-2 text-xs font-mono text-muted-foreground">{{ dialogItem.absolute_path }}</p>
            </div>

            <!-- Full Metadata JSON -->
            <details class="mt-4">
              <summary class="cursor-pointer text-sm font-medium">Full Metadata</summary>
              <div class="mt-2 overflow-auto">
                <pre class="text-xs">{{ dialogItem?.metadata ? JSON.stringify(dialogItem.metadata, null, 2) : '' }}</pre>
              </div>
            </details>

              <p>{{ item.type }}</p>
              <p>{{ item.mime_type }}</p>
              <div class="flex gap-2 flex-col">
                  <a :href="item.referrer_url" target="_blank">Referrer <SquareArrowOutUpRight></SquareArrowOutUpRight></a>
                  <a :href="item.true_thumbnail_url" target="_blank">Remote preview <SquareArrowOutUpRight></SquareArrowOutUpRight></a>
                  <a :href="item.true_original_url" target="_blank">Remote file <SquareArrowOutUpRight></SquareArrowOutUpRight></a>
              </div>
              <pre>{{ item }}</pre>
              <p>{{ item.downlaoded_at }}</p>
          </div>
        </div>
        <div class="flex items-center justify-center gap-3 border-t p-3">
<FileReactions v-if="dialogItem" :file="{ id: dialogItem.id }" :size="22"
                         @favorite="(emittedFile, emittedEvent) => emit('favorite', dialogItem, emittedEvent)"
                         @like="(emittedFile, emittedEvent) => emit('like', dialogItem, emittedEvent)"
                         @dislike="(emittedFile, emittedEvent) => emit('dislike', dialogItem, emittedEvent)"
                         @laughed-at="(emittedFile, emittedEvent) => emit('laughed-at', dialogItem, emittedEvent)" />
          <Button variant="outline" :disabled="!dialogItem" @click="openFullMediaInNewTab">Open</Button>
          <Button v-if="showFullErrorOverlay" variant="outline" :disabled="!dialogItem" @click="retryFullMedia(true)">Retry</Button>
          <Button v-if="isFileMarkedNotFound" variant="default" :disabled="!dialogItem" @click="clearNotFoundFlag">Clear Not Found</Button>
          <div v-if="(dialogItem?.seen_count ?? 0) > 0" class="flex items-center gap-1 text-xs text-muted-foreground">
            <Eye :size="14" />
            <span>{{ dialogItem?.seen_count ?? 0 }}</span>
          </div>
        </div>
      </div>

      <!-- Full-size Action Menu overlay -->
      <Teleport to="body">
        <div v-if="fullActionOpen" ref="fullOverlayRef" class="pointer-events-auto z-[1000]" :style="fullActionStyle">
          <ActionMenu :open="fullActionOpen" :options="fullMenuOptions" :initialPathLabels="fullActionInitialPathLabels || undefined"
@path-change="(pathLabels: string[]) => { if (pathLabels[0] === 'batch') { if (dialogItem && containerScopesFor(dialogItem).length) { broadcastHighlightForDialogItem() } } else { clearHighlight() } }"
                      @close="((fullActionOpen = false), clearHighlight())" />
        </div>
      </Teleport>
    </DialogContent>
  </Dialog>
</template>
