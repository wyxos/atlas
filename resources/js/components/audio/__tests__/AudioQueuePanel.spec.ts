import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import AudioQueuePanel from '@/components/audio/AudioQueuePanel.vue'
import { audioStore } from '@/stores/audio'

const loaderMocks = vi.hoisted(() => ({
  loadedFiles: {} as Record<number, any>,
  loadBatchFileDetails: vi.fn(),
}))
vi.mock('@/composables/useAudioFileLoader', () => ({ useAudioFileLoader: () => loaderMocks }))

describe('AudioQueuePanel', () => {
  beforeEach(() => {
    loaderMocks.loadBatchFileDetails.mockClear()
    audioStore.queue = [{ id: 1 }, { id: 2 }, { id: 3 }] as any
    audioStore.currentTrack = null as any
  })

  it('loads visible item details on scroller update', async () => {
    const RecycleStub = defineComponent({
      name: 'RecycleScroller',
      props: ['items', 'emitUpdate', 'itemSize', 'keyField'],
      emits: ['update'],
      template: '<div />',
    })

    const wrapper = mount(AudioQueuePanel, {
      props: { isOpen: true },
      global: {
        components: { RecycleScroller: RecycleStub },
        stubs: { Skeleton: true, X: true },
      },
    })

    // Manually emit update with visible range 0-1
    wrapper.findComponent(RecycleStub).vm.$emit('update', 0, 1, 0, 1)

    expect(loaderMocks.loadBatchFileDetails).toHaveBeenCalled()
    const ids = loaderMocks.loadBatchFileDetails.mock.calls[0][0]
    expect(ids).toEqual([1, 2])
  })
})