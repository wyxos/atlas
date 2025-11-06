import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, ref, h } from 'vue'
import AudioQueuePanel from '@/components/audio/AudioQueuePanel.vue'

const loaderMocks = vi.hoisted(() => ({
  loadedFiles: {} as Record<number, any>,
  loadBatchFileDetails: vi.fn(),
}))

const queueRef = ref<any[]>([])
const currentTrackRef = ref<any | null>(null)
const currentIndexRef = ref(0)
const playTrackAtIndex = vi.fn()

vi.mock('@/composables/useAudioFileLoader', () => ({ useAudioFileLoader: () => loaderMocks }))

vi.mock('@/stores/audio', () => ({
  useAudioPlayer: () => ({
    queue: queueRef,
    currentTrack: currentTrackRef,
    currentIndex: currentIndexRef,
    playTrackAtIndex,
  }),
}))

describe('AudioQueuePanel', () => {
  beforeEach(() => {
    loaderMocks.loadBatchFileDetails.mockClear()
    playTrackAtIndex.mockClear()
    queueRef.value = [{ id: 1 }, { id: 2 }, { id: 3 }]
    currentTrackRef.value = null
    currentIndexRef.value = 0
    loaderMocks.loadedFiles = {}
  })

  it('loads visible item details on scroller update', async () => {
    const RecycleStub = defineComponent({
      name: 'RecycleScroller',
      props: {
        items: { type: Array, default: () => [] },
        emitUpdate: { type: Boolean, default: false },
        itemSize: { type: Number, default: 0 },
        keyField: { type: String, default: 'id' },
      },
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

    wrapper.findComponent(RecycleStub).vm.$emit('update', 0, 1, 0, 1)

    expect(loaderMocks.loadBatchFileDetails).toHaveBeenCalledTimes(1)
    const ids = loaderMocks.loadBatchFileDetails.mock.calls[0][0]
    expect(ids).toEqual([1, 2])

    wrapper.unmount()
  })

  it('invokes playTrackAtIndex with autoplay when an item is clicked', async () => {
    const RecycleStub = defineComponent({
      name: 'RecycleScroller',
      props: {
        items: { type: Array, default: () => [] },
        emitUpdate: { type: Boolean, default: false },
        itemSize: { type: Number, default: 0 },
        keyField: { type: String, default: 'id' },
      },
      emits: ['update'],
      setup(props, { slots }) {
        return () => {
          const children = props.items.flatMap((item: any, index: number) =>
            slots.default ? slots.default({ item, index }) : [],
          )
          return h('div', {}, children)
        }
      },
    })

    loaderMocks.loadedFiles = {
      1: { id: 1, metadata: { payload: { title: 'One' } } },
      2: { id: 2, metadata: { payload: { title: 'Two' } } },
      3: { id: 3, metadata: { payload: { title: 'Three' } } },
    }

    const wrapper = mount(AudioQueuePanel, {
      props: { isOpen: true },
      global: {
        components: { RecycleScroller: RecycleStub },
        stubs: { Skeleton: true, X: true },
      },
    })

    const scroller = wrapper.findComponent(RecycleStub)
    const slot = scroller.vm.$slots.default as unknown as (ctx: { item: any; index: number }) => any[]
    const vnode = slot({ item: queueRef.value[1], index: 1 })[0]

    expect(typeof vnode.props?.onClick).toBe('function')

    ;(vnode.props!.onClick as (e?: any) => void)()

    expect(playTrackAtIndex).toHaveBeenCalledWith(1, { autoPlay: true })

    wrapper.unmount()
  })
})

