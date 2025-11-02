import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { markRaw } from 'vue'

export type FormLike = { data: () => Record<string, any>; defaults: (v: any) => void; reset: () => void }

export function createGridGetPage(
  form: FormLike,
  getUrl: () => string,
  opts?: { throttleMs?: number; normalizeItem?: (rawItem: any) => any; onResponse?: (payload: any) => void }
) {
  const throttleMs = opts?.throttleMs ?? 500
  let replaceTimer: any = null
  let pendingQueryParams: Record<string, any> | null = null

  function scheduleReplace(params: Record<string, any>) {
    pendingQueryParams = params
    if (replaceTimer) clearTimeout(replaceTimer)
    replaceTimer = setTimeout(() => {
      try {
        const queryString = new URLSearchParams(pendingQueryParams || {}).toString()
        router.replace({ url: window.location.pathname + '?' + queryString, preserveState: true, preserveScroll: true })
      } finally {
        replaceTimer = null
        pendingQueryParams = null
      }
    }, throttleMs)
  }

  const normalize = opts?.normalizeItem ?? ((rawItem: any) => {
    const normalizedItem: any = { ...rawItem }
    if (normalizedItem && normalizedItem.metadata) normalizedItem.metadata = markRaw(normalizedItem.metadata)
    if (Array.isArray(normalizedItem?.containers)) normalizedItem.containers = markRaw(normalizedItem.containers)
    if (normalizedItem && normalizedItem.listing_metadata) normalizedItem.listing_metadata = markRaw(normalizedItem.listing_metadata)
    if (normalizedItem && normalizedItem.detail_metadata) normalizedItem.detail_metadata = markRaw(normalizedItem.detail_metadata)
    return normalizedItem
  })

  return async function getPage(page: number) {
    const baseParams = { ...form.data() }
    const url = getUrl()
  const response = await axios.get(url, { params: { ...baseParams, page } })
  opts?.onResponse?.(response.data)
    const responseFilter = response.data?.filter || {}
    const items = (response.data?.files || []).map(normalize)

    form.defaults(responseFilter)
    form.reset()

    scheduleReplace({ ...baseParams, page: responseFilter.next ?? '' })

    return { items, nextPage: responseFilter.next ?? null }
  }
}
