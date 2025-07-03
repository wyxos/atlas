import { ref } from 'vue'

export interface Toast {
  id: string
  title?: string
  description?: string
  action?: {
    label: string
    onClick: () => void
  }
}

const toasts = ref<Toast[]>([])

export function toast(toast: Omit<Toast, 'id'>) {
  const id = Math.random().toString(36).substring(2, 9)
  toasts.value.push({ id, ...toast })

  setTimeout(() => {
    dismissToast(id)
  }, 5000)

  return {
    id,
    dismiss: () => dismissToast(id)
  }
}

export function dismissToast(id: string) {
  toasts.value = toasts.value.filter(t => t.id !== id)
}

export function useToast() {
  return {
    toasts,
    toast,
    dismissToast
  }
}
