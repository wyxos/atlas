import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountAudio } from './audioTestUtils';
import { useGlobalAudioPlayer } from '../composables/useGlobalAudioPlayer';
import type { AudioDetailsResponse, AudioIdsResponse } from '@/types/audio';

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
};

beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    Object.assign(global.window, {
        axios: mockAxios,
    });
});

afterEach(() => {
    useGlobalAudioPlayer().clear();
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('Audio metadata restore from file', () => {
    it('restores metadata from the file and refreshes the open sheet', async () => {
        let detailsRequests = 0;

        mockAxios.get.mockImplementation(async (url: string) => {
            if (url === '/api/audio/ids') {
                return {
                    data: {
                        ids: [7],
                        sources: { 7: 'local' },
                        source_ids: { 7: null },
                        spotify_uris: { 7: null },
                        cursor: { after_id: 0, next_after_id: null, has_more: false, max_id: 7 },
                        pagination: { per_page: 100, total: 1, total_pages: 1 },
                    } satisfies AudioIdsResponse,
                };
            }

            if (url === '/api/audio/7/metadata-proposals/latest') {
                return { data: { proposal: null } };
            }

            throw new Error(`Unexpected get URL: ${url}`);
        });

        mockAxios.post.mockImplementation(async (url: string) => {
            if (url === '/api/audio/details') {
                detailsRequests += 1;

                return audioDetailsResponse(detailsRequests > 1);
            }

            if (url === '/api/audio/7/metadata/restore-from-file') {
                return { data: { status: 'restored' } };
            }

            throw new Error(`Unexpected post URL: ${url}`);
        });

        const wrapper = await mountAudio();
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        await wrapper.get('[data-test="audio-track-title"]').trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Wrong Title');

        (document.body.querySelector('[data-test="audio-track-metadata-restore"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/audio/7/metadata/restore-from-file');
        expect(document.body.textContent).toContain('Metadata restored from file.');
        expect(document.body.textContent).toContain('Embedded Title');
        expect(document.body.textContent).toContain('Embedded Artist');
        expect(document.body.textContent).toContain('Embedded Album');
    });
});

function audioDetailsResponse(restored: boolean): { data: AudioDetailsResponse } {
    return {
        data: {
            items: [{
                id: 7,
                title: restored ? 'Embedded Title' : 'Wrong Title',
                source: 'local',
                source_id: null,
                spotify_uri: null,
                artists: [restored ? 'Embedded Artist' : 'Wrong Artist'],
                albums: [restored ? 'Embedded Album' : 'Wrong Album'],
                cover_url: null,
                duration_seconds: 139,
                reaction: null,
                blacklisted_at: null,
                previewed_count: 0,
                seen_count: 0,
            }],
        },
    };
}
