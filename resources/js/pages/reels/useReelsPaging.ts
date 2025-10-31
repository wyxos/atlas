import ReelsController from '@/actions/App/Http/Controllers/ReelsController'
import { createGridGetPage, type FormLike } from '@/pages/shared/createGridGetPage'

export function createReelsGetPage(form: FormLike) {
  return createGridGetPage(form, () => {
    const base = form.data() || {}
    return (base.data_url as string) || ReelsController.data().url
  })
}
