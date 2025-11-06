import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { useAudioReactions } from '@/composables/useAudioReactions'
import { bus } from '@/lib/bus'

vi.mock('@/actions/App/Http/Controllers/AudioController', () => ({
  react: ({ file }: any) => ({ url: `/audio/react/${file}` }),
}), { virtual: true })

const axiosMocks = vi.hoisted(() => ({
  post: vi.fn((url: string, body: any) => {
    // Echo back a state with the requested type set to true, others false
    const resp: any = { loved: false, liked: false, disliked: false, funny: false }
    if (body?.type === 'love') resp.loved = true
    if (body?.type === 'like') resp.liked = true
    if (body?.type === 'dislike') resp.disliked = true
    if (body?.type === 'funny') resp.funny = true
    return Promise.resolve({ data: resp })
  }),
}))
vi.mock('axios', () => ({ default: { post: axiosMocks.post } }))

describe('useAudioReactions', () => {
  beforeEach(() => {
    axiosMocks.post.mockClear()
  })
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('optimistically toggles, reconciles with server response, and emits bus event', async () => {
    const loaded: Record<number, any> = { 1: { id: 1, loved: false, liked: false, disliked: false, funny: false } }
    const { setReaction } = useAudioReactions(loaded)
    const busEmit = vi.spyOn(bus, 'emit')

    const target = { id: 1, loved: false, liked: false, disliked: false, funny: false }

    await setReaction(target, 'love')

    expect(axiosMocks.post).toHaveBeenCalledWith('/audio/react/1', { type: 'love' })
    expect(loaded[1]).toMatchObject({ loved: true, liked: false, disliked: false, funny: false })
    expect(busEmit).toHaveBeenCalledWith('file:reaction', expect.objectContaining({ id: 1, loved: true }))
  })

  it('wrapper methods call setReaction with proper types', async () => {
    const loaded: Record<number, any> = { 9: { id: 9 } }
    const { toggleFavorite, likeItem, dislikeItem, laughedAtItem } = useAudioReactions(loaded)
    await toggleFavorite({ id: 9 })
    await likeItem({ id: 9 })
    await dislikeItem({ id: 9 })
    await laughedAtItem({ id: 9 })
    const types = axiosMocks.post.mock.calls.map(([, b]) => b.type)
    expect(types).toEqual(expect.arrayContaining(['love', 'like', 'dislike', 'funny']))
  })
})