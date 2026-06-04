import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
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
        failed_files: 0,
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

describe('Audio metadata review run scope', () => {
    it('does not leak single metadata run progress into another track sheet', async () => {
        let runPollRequests = 0;

        mockAxios.get.mockImplementation(async (url: string) => {
            if (url === '/api/audio/ids') {
                return {
                    data: {
                        ids: [7, 8],
                        sources: { 7: 'local', 8: 'local' },
                        source_ids: { 7: null, 8: null },
                        spotify_uris: { 7: null, 8: null },
                        cursor: { after_id: 0, next_after_id: null, has_more: false, max_id: 8 },
                        pagination: { per_page: 100, total: 2, total_pages: 1 },
                    } satisfies AudioIdsResponse,
                };
            }

            if (url === '/api/audio/7/metadata-proposals/latest' || url === '/api/audio/8/metadata-proposals/latest') {
                return { data: { proposal: null } };
            }

            if (url === '/api/audio/metadata-runs/11') {
                runPollRequests += 1;

                return {
                    data: {
                        run: {
                            ...runFixture(),
                            status: 'running',
                            processed_files: 0,
                            current_file_id: 7,
                            current_step: 'fingerprint',
                            current_step_label: 'Generating audio fingerprint',
                        },
                        proposals: [],
                    },
                };
            }

            throw new Error(`Unexpected get URL: ${url}`);
        });

        mockAxios.post.mockImplementation(async (url: string) => {
            if (url === '/api/audio/details') {
                return { data: { items: trackDetails() } satisfies AudioDetailsResponse };
            }

            if (url === '/api/audio/7/metadata-runs') {
                return {
                    data: {
                        run: {
                            ...runFixture(),
                            status: 'pending',
                            processed_files: 0,
                            current_file_id: 7,
                            current_step: 'queued',
                        },
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

        const trackTitles = wrapper.findAll('[data-test="audio-track-title"]');
        await trackTitles[0].trigger('click');
        await flushPromises();

        (document.body.querySelector('[data-test="audio-track-metadata-run"]') as HTMLButtonElement).click();
        await flushPromises();

        vi.advanceTimersByTime(1600);
        await flushPromises();

        expect(runPollRequests).toBe(1);
        expect(document.body.textContent).toContain('Generating audio fingerprint');

        await trackTitles[1].trigger('click');
        await flushPromises();

        const secondTrackButton = document.body.querySelector('[data-test="audio-track-metadata-run"]') as HTMLButtonElement;
        expect(document.body.textContent).toContain('Second Track');
        expect(secondTrackButton.disabled).toBe(false);
        expect(document.body.textContent).not.toContain('Generating audio fingerprint');

        vi.advanceTimersByTime(1600);
        await flushPromises();

        expect(runPollRequests).toBe(2);
        expect(document.body.textContent).toContain('Second Track');
        expect(document.body.textContent).not.toContain('Generating audio fingerprint');
    });
});

function trackDetails(): AudioDetailsResponse['items'] {
    return [7, 8].map((id) => ({
        id,
        title: id === 7 ? 'First Track' : 'Second Track',
        source: 'local',
        source_id: null,
        spotify_uri: null,
        artists: [id === 7 ? 'First Artist' : 'Second Artist'],
        albums: [id === 7 ? 'First Album' : 'Second Album'],
        cover_url: null,
        duration_seconds: null,
        reaction: null,
        blacklisted_at: null,
        previewed_count: 0,
        seen_count: 0,
    }));
}
