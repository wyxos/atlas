import PhotosController from '@/actions/App/Http/Controllers/PhotosController'
import { createGridGetPage, type FormLike } from '@/pages/shared/createGridGetPage'

export function createPhotosGetPage(form: FormLike) {
  return createGridGetPage(form, () => {
    const base = form.data() || {}
    return (base.data_url as string) || PhotosController.data().url
  })
}
