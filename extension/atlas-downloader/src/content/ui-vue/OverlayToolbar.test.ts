// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import OverlayToolbar from './OverlayToolbar.vue';

function buildProps() {
  return {
    open: true,
    left: 100,
    top: 200,
    resolutionText: '1920x1080',
    statusText: 'Queued',
    progressVisible: true,
    progressPercent: 24,
    progressState: 'active' as const,
    buttons: [
      {
        type: 'like',
        className: 'like',
        label: 'Like',
        pathDs: ['M1 1L2 2'],
        active: true,
        pending: false,
        queued: false,
        disabled: false,
      },
    ],
  };
}

describe('OverlayToolbar', () => {
  it('emits reaction and pointer events', async () => {
    const wrapper = mount(OverlayToolbar, { props: buildProps() });

    await wrapper.find('.atlas-downloader-media-toolbar').trigger('pointerenter');
    await wrapper.find('.atlas-downloader-media-toolbar').trigger('pointerleave');
    await wrapper.find('.atlas-downloader-reaction-btn.like').trigger('click');

    expect(wrapper.emitted('pointerEnter')).toHaveLength(1);
    expect(wrapper.emitted('pointerLeave')).toHaveLength(1);
    expect(wrapper.emitted('reaction')).toEqual([['like']]);
  });
});
