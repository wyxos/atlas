// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import JsonTree from './JsonTree.vue';

describe('JsonTree', () => {
  it('renders primitive values with type hinting', () => {
    const wrapper = mount(JsonTree, {
      props: {
        name: 'status',
        value: 'ok',
      },
    });

    expect(wrapper.find('.atlas-downloader-json-key').text()).toContain('"status"');
    expect(wrapper.find('[data-type="string"]').text()).toContain('"ok"');
  });

  it('renders nested object values', () => {
    const wrapper = mount(JsonTree, {
      props: {
        value: {
          count: 2,
          nested: {
            active: true,
          },
        },
        expanded: true,
      },
    });

    expect(wrapper.find('.atlas-downloader-json-node').exists()).toBe(true);
    expect(wrapper.text()).toContain('"count"');
    expect(wrapper.text()).toContain('"nested"');
  });
});