import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import FileViewerSheet from './FileViewerSheet.vue';
import type { File } from '@/types/file';

function makeFile(overrides: Partial<File> = {}): File {
    return {
        id: 1,
        source: 'CivitAI',
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
        url: 'https://image.civitai.com/example/original=true/example.jpeg',
        file_url: null,
        referrer_url: null,
        path: 'downloads/existing.jpg',
        absolute_path: 'D:\\storage\\app\\downloads\\existing.jpg',
        absolute_preview_path: null,
        preview_url: null,
        cover_url: null,
        disk_url: null,
        preview_file_url: null,
        poster_url: null,
        preview_path: null,
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
        downloaded_at: '2024-01-01T00:00:00Z',
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

describe('FileViewerSheet download actions', () => {
    it('offers re-download for downloaded non-local files that are not flagged 404', async () => {
        const fileData = makeFile();

        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: fileData.id,
                isLoading: false,
                fileData,
            },
        });

        expect(wrapper.find('[data-test="file-redownload"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="file-mark-corrupted"]').exists()).toBe(false);

        await wrapper.get('[data-test="file-redownload"]').trigger('click');

        expect(wrapper.emitted('redownload-file')).toEqual([[fileData.id]]);
    });

    it('offers mark corrupted instead of re-download for downloaded non-local files flagged 404', async () => {
        const fileData = makeFile({
            not_found: true,
        });

        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: fileData.id,
                isLoading: false,
                fileData,
            },
        });

        expect(wrapper.find('[data-test="file-redownload"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="file-mark-corrupted"]').exists()).toBe(true);

        await wrapper.get('[data-test="file-mark-corrupted"]').trigger('click');

        expect(wrapper.emitted('mark-corrupted-file')).toEqual([[fileData.id]]);
    });

    it('does not offer re-download or corrupted cleanup for local files', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: 1,
                isLoading: false,
                fileData: makeFile({
                    source: 'local',
                    not_found: true,
                    path: 'imports/existing.jpg',
                }),
            },
        });

        expect(wrapper.find('[data-test="file-redownload"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="file-mark-corrupted"]').exists()).toBe(false);
    });
});
