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
        blacklisted_at: null,
        blacklist_reason: null,
        downloaded: true,
        downloaded_at: null,
        download_progress: 0,
        not_found: false,
        listing_metadata: null,
        detail_metadata: null,
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
});

