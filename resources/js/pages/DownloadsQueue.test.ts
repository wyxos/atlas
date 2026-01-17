import { mount } from '@vue/test-utils';
import DownloadsQueue from './DownloadsQueue.vue';

it('renders the downloads queue placeholder', () => {
    const wrapper = mount(DownloadsQueue);

    expect(wrapper.text()).toContain('Downloads queue placeholder.');
});
