import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref, nextTick } from 'vue'
import GridItem from '@/components/browse/GridItem.vue'

describe('GridItem interactions', () => {
  async function mountItem() {
    const item = { id: 1, type: 'image', preview: 'about:blank', containers: [] }
    const io = { observer: {}, register: (_el: Element, fn: () => void) => fn(), unregister: () => {} }
    const wrapper = mount(GridItem as any, {
      props: { item },
      global: {
        provide: {
          'browse-items': ref([item]),
          'browse-io': io,
          'browse-container-counts': new Map(),
          'browse-scroller': { removeMany: () => {}, remove: () => {} },
          'browse-schedule-refresh': () => {},
        },
        stubs: {
          FileReactions: { template: '<div />' },
          Button: { template: '<button><slot /></button>' },
          LoaderOverlay: { template: '<div />' },
          TooltipProvider: { template: '<div><slot /></div>' },
          Tooltip: { template: '<div><slot /><slot name="content" /></div>' },
          TooltipTrigger: { template: '<div><slot /></div>' },
          TooltipContent: { template: '<div><slot /></div>' },
          ActionMenu: { template: '<div />' },
        },
      },
      attachTo: document.body,
    })
    // Wait for onMounted -> IO.register callback -> reactive update -> DOM render
    await nextTick()
    await nextTick()
    return wrapper
  }

  it('emits like on alt+click of preview image', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)
    await img.trigger('click', { altKey: true, button: 0 })
    expect(wrapper.emitted('like')).toBeTruthy()
    wrapper.unmount()
  })

  it('emits dislike on alt+contextmenu', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')
    await img.trigger('contextmenu', { altKey: true, button: 2 })
    expect(wrapper.emitted('dislike')).toBeTruthy()
    wrapper.unmount()
  })

  it('emits open on plain click', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')
    await img.trigger('click')
    expect(wrapper.emitted('open')).toBeTruthy()
    wrapper.unmount()
  })

  it('opens URL in new tab on middle-click (auxclick)', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)

    // Mock window.open
    const originalOpen = window.open
    let openedUrl: string | null = null
    window.open = ((url: string) => {
      openedUrl = url
      return null
    }) as any

    // Trigger middle-click (button 1) without Alt
    await img.trigger('mousedown', { button: 1, altKey: false })
    await img.trigger('auxclick', { button: 1, altKey: false })

    expect(openedUrl).toBe('about:blank')

    // Restore window.open
    window.open = originalOpen
    wrapper.unmount()
  })

  it('emits favorite on alt+middle-click (alt+auxclick)', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)

    // Trigger alt+middle-click (button 1 with Alt)
    await img.trigger('mousedown', { button: 1, altKey: true })
    await img.trigger('auxclick', { button: 1, altKey: true })

    expect(wrapper.emitted('favorite')).toBeTruthy()
    wrapper.unmount()
  })

  it('does not emit open when middle-clicking', async () => {
    const wrapper = await mountItem()
    const img = wrapper.find('img')

    // Mock window.open to prevent errors
    const originalOpen = window.open
    window.open = (() => null) as any

    // Middle-click should not trigger the open event (only auxclick for new tab)
    await img.trigger('mousedown', { button: 1, altKey: false })
    await img.trigger('auxclick', { button: 1, altKey: false })

    expect(wrapper.emitted('open')).toBeFalsy()

    window.open = originalOpen
    wrapper.unmount()
  })
})
