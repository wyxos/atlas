import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref, nextTick } from 'vue'
import { createBatchReact } from '@/pages/browse/useBatchReact'

const axiosMocks = vi.hoisted(() => ({ post: vi.fn(() => Promise.resolve({ data: {} })) }))
vi.mock('axios', () => ({ default: { post: axiosMocks.post } }))

vi.mock('@/actions/App/Http/Controllers/BrowseController', () => ({
  batchReact: () => ({ url: '/browse/batch-react' }),
  batchUnblacklist: () => ({ url: '/browse/batch-unblacklist' }),
  react: ({ file }: any) => ({ url: `/browse/react/${file}` }),
}), { virtual: true })

describe('createBatchReact dialog advance', () => {
  beforeEach(() => { axiosMocks.post.mockClear() })

  it('advances dialog item when current is removed by batch', async () => {
    const items = ref<any[]>([
      { id: 1, loved: false, containers: [{ key: 'tag', value: 'x' }] },
      { id: 2, loved: false, containers: [{ key: 'tag', value: 'x' }] },
      { id: 3, loved: false, containers: [{ key: 'tag', value: 'y' }] },
    ])
    const scroller = ref<any>({ removeMany: vi.fn(async () => {}), loadNext: vi.fn(async () => {}), layout: vi.fn() })
    const dialogOpen = ref<boolean>(true)
    const dialogItem = ref<any>(items.value[0])

    const batchReact = createBatchReact({ items, scroller, dialogOpen, dialogItem })
    await batchReact('dislike', { key: 'tag', value: 'x' })
    await nextTick()

    // Should have advanced to the next available (id 3)
    expect(dialogItem.value?.id).toBe(3)
    // removeMany called with two snapshots
    expect(scroller.value.removeMany).toHaveBeenCalled()
  })
})