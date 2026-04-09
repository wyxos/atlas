import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
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
        auto_disliked: false,
        blacklisted_at: null,
        blacklist_reason: null,
        downloaded: true,
        downloaded_at: null,
        download_progress: 0,
        not_found: false,
        listing_metadata: null,
        detail_metadata: null,
        containers: [],
        created_at: '2024-01-01T00:00:00Z',
        updated_at: '2024-01-01T00:00:00Z',
        ...overrides,
    };
}

describe('FileViewerSheet', () => {
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
                            file_stats: {
                                unreacted: 12,
                                blacklisted: 9,
                                disliked: 3,
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
        expect(text).toContain('Disliked');
        expect(text).toContain('3');
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
    });
});

