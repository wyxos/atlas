import PhotosController from '@/actions/App/Http/Controllers/PhotosController'
import { createGridGetPage, type FormLike } from '@/pages/shared/createGridGetPage'
import { enqueueModeration, flushModeration } from '@/lib/moderation'

export function createPhotosGetPage(form: FormLike) {
  return createGridGetPage(form, () => {
    const base = form.data() || {}
    return (base.data_url as string) || PhotosController.data().url
  }, {
    buildParams: () => ({
      mime_type: (form as any).mime_type,
    }),
    onResponse(payload) {
      const moderation = payload?.moderation || {}
      const ids = Array.isArray(moderation?.ids) ? moderation.ids : []
      const count = Number(moderation?.blacklisted_count ?? 0)
      if (ids.length === 0 && count <= 0) {
        return
      }

      const previews = Array.isArray(moderation?.previews)
        ? moderation.previews
            .map((entry: any) => entry?.preview || '')
            .filter(Boolean)
            .slice(0, 4)
        : []
      const previewTitles = Array.isArray(moderation?.previews)
        ? moderation.previews
            .map((entry: any) => entry?.title || '')
            .filter(Boolean)
            .slice(0, 4)
        : []

      try {
        enqueueModeration(ids, previews, previewTitles)
      } catch {}

      window.setTimeout(() => {
        try {
          flushModeration()
        } catch {}
      }, 500)
    },
  })
}
