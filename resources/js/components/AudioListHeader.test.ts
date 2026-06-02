import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AudioListHeader from './AudioListHeader.vue';

describe('AudioListHeader', () => {
    it('renders enlarged mobile controls with metadata scan and queue actions before filtering', async () => {
        const wrapper = mount(AudioListHeader, {
            props: {
                activeFilterLabel: 'All',
                canShufflePlay: true,
                hasQueue: true,
            },
        });

        const controls = wrapper.findAll('button');

        expect(wrapper.get('[data-test="audio-list-header"]').classes()).toEqual(expect.arrayContaining([
            'h-14',
            'md:h-10',
        ]));
        expect(wrapper.get('[data-test="audio-playlists-cta"]').classes()).toEqual(expect.arrayContaining(['size-10', 'md:size-7']));
        expect(wrapper.get('[data-test="audio-shuffle-play-cta"]').classes()).toEqual(expect.arrayContaining(['size-10', 'md:size-7']));
        expect(wrapper.get('[data-test="audio-metadata-scan-cta"]').classes()).toEqual(expect.arrayContaining(['size-10', 'md:size-7']));
        expect(wrapper.get('[data-test="audio-queue-cta"]').classes()).toEqual(expect.arrayContaining(['size-10', 'md:size-7']));
        expect(wrapper.get('[data-test="audio-filter-cta"]').classes()).toEqual(expect.arrayContaining(['size-10', 'md:size-7']));
        expect(controls[2]?.attributes('aria-label')).toBe('Scan metadata');
        expect(controls[3]?.attributes('aria-label')).toBe('Open queue');
        expect(controls[4]?.attributes('aria-label')).toBe('Filter: All');

        await wrapper.get('[data-test="audio-metadata-scan-cta"]').trigger('click');
        await wrapper.get('[data-test="audio-queue-cta"]').trigger('click');

        expect(wrapper.emitted('scanMetadata')).toHaveLength(1);
        expect(wrapper.emitted('toggleQueue')).toHaveLength(1);
    });
});
