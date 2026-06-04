import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import type { VibeFullscreenPreviewItem } from '@wyxos/vibe';
import FileViewerSheet from './FileViewerSheet.vue';
import type { File } from '@/types/file';
import { copyToClipboard } from '@/utils/clipboard';

vi.mock('@/utils/clipboard', () => ({
    copyToClipboard: vi.fn().mockResolvedValue(undefined),
}));

function makeFile(overrides: Partial<File> = {}): File {
    return {
        id: 1,
        source: 'Local',
        source_id: null,
        filename: 'test.jpg',
        ext: 'jpg',
        size: 1024,
        width: null,
        height: null,
        mime_type: 'image/jpeg',
        hash: null,
        title: null,
        description: null,
        url: null,
        file_url: null,
        referrer_url: null,
        path: 'downloads/aa/bb/test.jpg',
        absolute_path: 'D:\\storage\\app\\downloads\\aa\\bb\\test.jpg',
        absolute_preview_path: 'D:\\storage\\app\\thumbnails\\aa\\bb\\test.preview.jpg',
        preview_url: null,
        cover_url: null,
        disk_url: null,
        preview_file_url: null,
        poster_url: null,
        preview_path: 'thumbnails/aa/bb/test.preview.jpg',
        poster_path: null,
        tags: null,
        parent_id: null,
        chapter: null,
        previewed_at: null,
        previewed_count: 0,
        seen_at: null,
        seen_count: 0,
        auto_blacklisted: false,
        blacklisted_at: null,
        downloaded: true,
        downloaded_at: null,
        imported_at: null,
        download_progress: 0,
        not_found: false,
        listing_metadata: null,
        detail_metadata: null,
        metadata: null,
        containers: [],
        capabilities: {
            refresh_source_media: false,
            watch_source_and_refresh: false,
            unwatch_source_account: false,
        },
        created_at: '2024-01-01T00:00:00Z',
        updated_at: '2024-01-01T00:00:00Z',
        ...overrides,
    };
}

function makePreview(overrides: Partial<VibeFullscreenPreviewItem> = {}): VibeFullscreenPreviewItem {
    return {
        asset: {
            height: 300,
            kind: 'image',
            label: 'Next file',
            url: 'https://example.test/next-preview.jpg',
            width: 900,
        },
        index: 1,
        item: {
            id: 'next-file',
            preview: {
                height: 300,
                mediaType: 'image',
                url: 'https://example.test/next-preview.jpg',
                width: 900,
            },
            title: 'Next file',
            type: 'image',
            url: 'https://example.test/next-original.jpg',
        },
        ...overrides,
    };
}

describe('FileViewerSheet', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('copies absolute paths when clicking relative paths', async () => {
        Object.defineProperty(navigator, 'platform', { value: 'Win32', configurable: true });

        const fileData = makeFile({
            absolute_path: 'D:/storage/app/downloads/aa/bb/test.jpg',
            absolute_preview_path: 'D:/storage/app/thumbnails/aa/bb/test.preview.jpg',
        });

        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: fileData.id,
                fileData,
                isLoading: false,
            },
        });

        await wrapper.get('[data-test="file-path"]').trigger('click');
        await wrapper.get('[data-test="preview-path"]').trigger('click');

        expect(copyToClipboard).toHaveBeenCalledWith(
            'D:\\storage\\app\\downloads\\aa\\bb\\test.jpg',
            'Path',
            { showToast: false }
        );
        expect(copyToClipboard).toHaveBeenCalledWith(
            'D:\\storage\\app\\thumbnails\\aa\\bb\\test.preview.jpg',
            'Path',
            { showToast: false }
        );
    });

    it('renders container state and stats when file details include containers', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: 1,
                isLoading: false,
                fileData: makeFile({
                    containers: [
                        {
                            id: 17,
                            type: 'gallery',
                            source: 'CivitAI',
                            source_id: 'abc123',
                            referrer: 'https://example.com/gallery/abc123',
                            blacklisted: true,
                            blacklisted_at: '2026-04-06T09:30:00Z',
                            action_type: 'blacklist',
                            blacklist_previewed_count_mode: 'preserve',
                            file_stats: {
                                unreacted: 12,
                                blacklisted: 9,
                                positive: 4,
                            },
                        },
                    ],
                }),
            },
        });

        const text = wrapper.text();

        expect(text).toContain('Containers');
        expect(text).toContain('#17 gallery');
        expect(text).toContain('CivitAI');
        expect(text).toContain('abc123');
        expect(text).toContain('blacklisted');
        expect(text).toContain('Action');
        expect(text).toContain('Unreacted');
        expect(text).toContain('12');
        expect(text).toContain('Blacklisted');
        expect(text).toContain('9');
        expect(text).toContain('Positive');
        expect(text).toContain('4');
    });

    it('supports embedded layout without fixed overlay width classes', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                embedded: true,
                isOpen: true,
                fileId: 1,
                isLoading: false,
                fileData: makeFile(),
            },
        });

        const root = wrapper.get('div');

        expect(root.classes()).toContain('w-full');
        expect(root.classes()).not.toContain('w-80');
        expect(root.classes()).not.toContain('max-w-80');
        expect(root.classes()).not.toContain('w-[30rem]');
        expect(root.classes()).not.toContain('max-w-[30rem]');
    });

    it('renders the standalone sheet at the wider overlay width', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: 1,
                isLoading: false,
                fileData: makeFile(),
            },
        });

        const root = wrapper.get('div');

        expect(root.classes()).toContain('w-[30rem]');
        expect(root.classes()).toContain('max-w-[30rem]');
    });

    it('owns the sheet enter and leave transition', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: 1,
                isLoading: false,
                fileData: makeFile(),
            },
        });

        const transition = wrapper.findComponent({ name: 'Transition' });

        expect(transition.exists()).toBe(true);
        expect(transition.props('appear')).toBe(true);
        expect(transition.props('enterFromClass')).toContain('translate-x-full');
        expect(transition.props('leaveToClass')).toContain('translate-x-full');
    });

    it('renders saved provider metadata as structured sections', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: 1,
                isLoading: false,
                fileData: makeFile({
                    listing_metadata: {
                        premium_folder_data: {
                            type: 'watchers',
                            has_access: false,
                        },
                        _atlas_media: {
                            mode: 'content',
                        },
                    },
                    detail_metadata: {
                        download_mode: 'content',
                    },
                    metadata: {
                        payload: {
                            is_mature: false,
                            stats: {
                                comments: 0,
                            },
                        },
                    },
                }),
            },
        });

        const text = wrapper.get('[data-test="file-provider-metadata"]').text();

        expect(text).toContain('Provider Metadata');
        expect(text).toContain('Listing Metadata');
        expect(text).toContain('Premium Folder Data');
        expect(text).toContain('Has Access');
        expect(text).toContain('No');
        expect(text).toContain('Atlas Media');
        expect(text).toContain('Detail Metadata');
        expect(text).toContain('Download Mode');
        expect(text).toContain('Stored Metadata Payload');
        expect(text).toContain('Is Mature');
    });

    it('does not render the source media refresh action in the sheet', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: 1,
                isLoading: false,
                fileData: makeFile({
                    source: 'deviantart.com',
                    capabilities: {
                        refresh_source_media: true,
                        watch_source_and_refresh: true,
                        unwatch_source_account: true,
                    },
                }),
            },
        });

        expect(wrapper.find('[data-test="refresh-source-media"]').exists()).toBe(false);
        expect(wrapper.emitted('source-media-refreshed')).toBeUndefined();
    });

    it('renders the selected item prompt in the sheet', async () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: 1,
                isLoading: false,
                fileData: makeFile(),
                prompt: 'high detail test prompt',
                showPrompt: true,
            },
        });

        expect(wrapper.get('[data-test="file-prompt"]').text()).toContain('high detail test prompt');

        await wrapper.get('[data-test="copy-prompt"]').trigger('click');

        expect(copyToClipboard).toHaveBeenCalledWith('high detail test prompt', 'Prompt', { showToast: false });
    });

    it('renders fullscreen next previews fixed at the bottom of the embedded sheet', async () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                embedded: true,
                isOpen: true,
                fileId: 1,
                isLoading: false,
                fileData: makeFile(),
                nextPreviews: [
                    makePreview(),
                    makePreview({
                        asset: {
                            height: 600,
                            kind: 'image',
                            label: 'Second file',
                            url: 'https://example.test/second-preview.jpg',
                            width: 720,
                        },
                        index: 2,
                        item: {
                            id: 'second-file',
                            title: 'Second file',
                            type: 'image',
                            url: 'https://example.test/second-original.jpg',
                        },
                    }),
                ],
                totalItems: 20,
            },
        });

        const strip = wrapper.get('[data-testid="fullscreen-sheet-next-previews"]');
        const previews = wrapper.findAll('[data-testid="fullscreen-sheet-next-preview"]');

        expect(strip.classes()).toContain('absolute');
        expect(strip.classes()).toContain('bottom-0');
        expect(previews).toHaveLength(2);
        expect(previews[0].classes()).toContain('h-[150px]');
        expect(previews[0].classes()).toContain('w-[150px]');
        expect(previews[0].attributes('data-index')).toBe('1');
        expect(previews[0].get('img').attributes('src')).toBe('https://example.test/next-preview.jpg');
        expect(previews[0].get('img').classes()).toContain('object-cover');
        expect(wrapper.findAll('[data-testid="fullscreen-sheet-next-preview-spinner"]')).toHaveLength(2);

        await previews[0].get('img').trigger('load');

        expect(wrapper.findAll('[data-testid="fullscreen-sheet-next-preview-spinner"]')).toHaveLength(1);
        expect(previews[0].get('img').classes()).toContain('opacity-100');

        await previews[0].trigger('click');

        expect(wrapper.emitted('select-preview')).toEqual([[1]]);
    });
});
