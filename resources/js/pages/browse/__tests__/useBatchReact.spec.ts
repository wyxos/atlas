import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { createBatchReact } from '@/pages/browse/useBatchReact'
import { undoManager } from '@/lib/undo'

const axiosMocks = vi.hoisted(() => ({
  post: vi.fn(() => Promise.resolve({ data: {} })),
}))
vi.mock('axios', () => ({ default: { post: axiosMocks.post } }))

vi.mock('@/actions/App/Http/Controllers/BrowseController', () => ({
  batchReact: () => ({ url: '/browse/batch-react' }),
  batchUnblacklist: () => ({ url: '/browse/batch-unblacklist' }),
  react: ({ file }: any) => ({ url: `/browse/react/${file}` }),
}), { virtual: true })

describe('createBatchReact', () => {
  beforeEach(() => {
    axiosMocks.post.mockClear()
  })

  it('removes scoped items, posts batch react, and pushes undo that can revert/undo network', async () => {
    const items = ref<any[]>([
      { id: 1, loved: false, containers: [{ key: 'tag', value: 'x' }] },
      { id: 2, loved: true, containers: [{ key: 'tag', value: 'x' }] },
      { id: 3, containers: [{ key: 'tag', value: 'y' }] },
    ])
    const scroller = ref<any>({ removeMany: vi.fn(async () => {}), loadNext: vi.fn(async () => {}), layout: vi.fn() })
    const dialogOpen = ref<boolean>(true)
    const dialogItem = ref<any>(items.value[0])

    const pushed: any[] = []
    vi.spyOn(undoManager, 'push').mockImplementation((action: any) => { pushed.push(action) })

    const batchReact = createBatchReact({ items, scroller, dialogOpen, dialogItem })
    await batchReact('dislike', { key: 'tag', value: 'x' })

    // scroller removeMany called with two items
    expect(scroller.value.removeMany).toHaveBeenCalled()
    // batch endpoint called once
    expect(axiosMocks.post).toHaveBeenCalledWith(
      '/browse/batch-react',
      expect.objectContaining({ ids: expect.arrayContaining([1, 2]), type: 'dislike' }),
    )

    // Undo action captured
    expect(pushed.length).toBe(1)
    const action = pushed[0]

    // Revert UI reinserts snapshots
    action.revertUI()
    expect(items.value.find((x) => x.id === 1)).toBeTruthy()

    // Undo network path should post unblacklist and per-file react
    await action.undo()
    expect(axiosMocks.post).toHaveBeenCalledWith('/browse/batch-unblacklist', expect.anything())
    expect(axiosMocks.post).toHaveBeenCalledWith('/browse/react/1', expect.anything())
  })
})