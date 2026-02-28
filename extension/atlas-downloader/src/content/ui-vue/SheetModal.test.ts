// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SheetModal from './SheetModal.vue';

function buildProps() {
  return {
    open: true,
    version: '1.2.3',
    metaText: '2 found • 1 selected',
    items: [
      {
        index: 0,
        tag_name: 'img',
        url: 'https://example.com/a.jpg',
        preview_url: 'https://example.com/a.jpg',
        width: 1200,
        height: 900,
        selected: true,
        status: '',
        statusClass: '',
        atlas: {
          exists: true,
          downloaded: false,
          blacklisted: false,
          reaction: { type: 'like' },
        },
        reactionPending: null,
        reactionQueued: null,
      },
    ],
    queueDisabled: false,
    refreshDisabled: false,
    checkAtlasDisabled: false,
    selectAllDisabled: false,
    selectNoneDisabled: false,
    requestTrace: [
      {
        id: 1,
        messageType: 'atlas-react',
        path: '/api/extension/files/react',
        state: 'completed',
        startedAt: 1_700_000_000_000,
        finishedAt: 1_700_000_000_123,
        durationMs: 123,
        payload: {
          type: 'atlas-react',
          payload: {
            url: 'https://example.com/a.jpg',
          },
        },
        response: {
          ok: true,
          data: {
            file_id: 999,
          },
        },
        errorMessage: null,
      },
    ],
    debugTargetUrl: null,
    debugPayloads: {},
    reactions: [
      {
        type: 'like',
        className: 'like',
        label: 'Like',
        pathDs: ['M1 1L2 2'],
      },
    ],
    blacklistAction: {
      type: 'blacklist',
      className: 'blacklist',
      label: 'Blacklist',
      pathDs: ['M2 2L3 3'],
    },
  };
}

describe('SheetModal', () => {
  it('emits close when overlay is clicked', async () => {
    const wrapper = mount(SheetModal, { props: buildProps() });
    await wrapper.find('.atlas-downloader-overlay').trigger('click');

    expect(wrapper.emitted('close')).toHaveLength(1);
  });

  it('emits selection and reaction events', async () => {
    const wrapper = mount(SheetModal, { props: buildProps() });

    await wrapper.find('input[type="checkbox"]').setValue(false);
    expect(wrapper.emitted('updateSelected')).toEqual([[0, false]]);

    await wrapper.find('.atlas-downloader-reaction-btn.like').trigger('click');
    expect(wrapper.emitted('react')).toEqual([[0, 'like']]);
  });

  it('renders request payload, response, and duration in requests tab', async () => {
    const wrapper = mount(SheetModal, { props: buildProps() });

    await wrapper.findAll('.atlas-downloader-tab')[1].trigger('click');

    expect(wrapper.find('.atlas-downloader-request-path').text()).toContain('/api/extension/files/react');
    expect(wrapper.find('.atlas-downloader-request-duration').text()).toContain('123 ms');
    expect(wrapper.text()).toContain('Payload');
    expect(wrapper.text()).toContain('Response');
    expect(wrapper.find('.atlas-downloader-json-key').exists()).toBe(true);
  });
});