import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountAudio } from './audioTestUtils';
import { useGlobalAudioPlayer } from '../composables/useGlobalAudioPlayer';
import type { AudioDetailsResponse, AudioIdsResponse, AudioMetadataRun } from '@/types/audio';

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
};

function runFixture(): AudioMetadataRun {
    return {
        id: 11,
        scope: 'single',
        source_filter: 'local',
        status: 'completed',
        total_files: 1,
        processed_files: 1,
        proposal_count: 0,
        failed_files: 1,
        current_file_id: null,
        current_step: null,
        current_step_label: null,
        error: null,
        created_at: null,
        started_at: null,
        finished_at: null,
    };
}

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

describe('Audio metadata review failure state', () => {
    it('shows lookup failures instead of reporting no metadata changes', async () => {
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
                return {
                    data: {
                        items: [{
                            id: 7,
                            title: 'Original Track',
                            source: 'local',
                            source_id: null,
                            spotify_uri: null,
                            artists: ['Original Artist'],
                            albums: ['Original Album'],
                            cover_url: null,
                            duration_seconds: null,
                            reaction: null,
                            blacklisted_at: null,
                            previewed_count: 0,
                            seen_count: 0,
                        }],
                    } satisfies AudioDetailsResponse,
                };
            }

            if (url === '/api/audio/7/metadata-runs') {
                return {
                    data: {
                        run: runFixture(),
                        proposal: null,
                    },
                };
            }

            throw new Error(`Unexpected post URL: ${url}`);
        });

        const wrapper = await mountAudio();
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        await wrapper.get('[data-test="audio-track-title"]').trigger('click');
        await flushPromises();

        (document.body.querySelector('[data-test="audio-track-metadata-run"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(document.body.textContent).toContain('Metadata lookup failed.');
        expect(document.body.textContent).not.toContain('No metadata changes found.');
    });
});
