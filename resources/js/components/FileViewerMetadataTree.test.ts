import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import FileViewerMetadataTree from './FileViewerMetadataTree.vue';
import { copyToClipboard } from '@/utils/clipboard';

vi.mock('@/utils/clipboard', () => ({
    copyToClipboard: vi.fn().mockResolvedValue(undefined),
}));

describe('FileViewerMetadataTree', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders nested metadata as compact connector rows', () => {
        const wrapper = mount(FileViewerMetadataTree, {
            props: {
                value: {
                    listing_metadata: {
                        id: 60675168,
                        url: 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7W/long-file-name.jpeg',
                        dimensions: {
                            width: 1152,
                            height: 1728,
                        },
                        previews: [
                            {
                                type: 'image',
                            },
                        ],
                    },
                },
            },
        });

        expect(wrapper.text()).toContain('Listing Metadata');
        expect(wrapper.text()).toContain('4 fields');
        expect(wrapper.text()).toContain('Dimensions');
        expect(wrapper.text()).toContain('2 fields');
        expect(wrapper.text()).toContain('Previews');
        expect(wrapper.text()).toContain('1 item');
        expect(wrapper.findAll('[data-testid="metadata-tree-branch"]').length).toBeGreaterThan(1);
        expect(wrapper.findAll('[data-testid="metadata-tree-connector"]').length).toBeGreaterThan(1);
    });

    it('truncates scalar values, exposes the full value on hover, and copies the full value', async () => {
        const url = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7W/4f95c3d2-19a8-450c-8522-4fc323f6d50a/original=true/4f95c3d2-19a8-450c-8522-4fc323f6d50a.jpeg';
        const wrapper = mount(FileViewerMetadataTree, {
            props: {
                value: {
                    url,
                },
            },
        });

        const urlButton = wrapper.findAll('[data-testid="metadata-scalar-copy"]')
            .find((button) => button.attributes('data-label') === 'URL');

        if (!urlButton) {
            throw new Error('Expected URL metadata copy row to render.');
        }

        expect(urlButton.classes()).toContain('flex');
        expect(urlButton.attributes('title')).toBe(url);
        expect(urlButton.get('[data-testid="metadata-scalar-label"]').text()).toBe('URL');
        expect(urlButton.get('[data-testid="metadata-scalar-value"]').classes()).toContain('truncate');

        await urlButton.trigger('click');

        expect(copyToClipboard).toHaveBeenCalledWith(url, 'URL', { showToast: false });
    });

    it('formats scalar edge cases before copying', async () => {
        const wrapper = mount(FileViewerMetadataTree, {
            props: {
                value: {
                    has_access: false,
                    empty_value: '',
                    null_value: null,
                },
            },
        });

        const scalarRows = wrapper.findAll('[data-testid="metadata-scalar-copy"]');

        expect(scalarRows.map((row) => row.get('[data-testid="metadata-scalar-label"]').text())).toEqual([
            'Has Access',
            'Empty Value',
            'Null Value',
        ]);
        expect(scalarRows.map((row) => row.get('[data-testid="metadata-scalar-value"]').text())).toEqual([
            'No',
            '""',
            'null',
        ]);

        await scalarRows[0].trigger('click');
        await scalarRows[1].trigger('click');
        await scalarRows[2].trigger('click');

        expect(copyToClipboard).toHaveBeenCalledWith('No', 'Has Access', { showToast: false });
        expect(copyToClipboard).toHaveBeenCalledWith('""', 'Empty Value', { showToast: false });
        expect(copyToClipboard).toHaveBeenCalledWith('null', 'Null Value', { showToast: false });
    });
});
