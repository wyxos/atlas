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
        id: 41,
        scope: 'all',
        source_filter: 'all',
        status: 'pending',
        total_files: 10,
        processed_files: 0,
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

describe('Audio metadata library run progress', () => {
    it('restores active whole-library metadata progress after mounting the page', async () => {
        let runPollRequests = 0;

        mockAxios.get.mockImplementation(async (url: string) => {
            if (url === '/api/audio/ids') {
                return {
                    data: {
                        ids: [7, 8],
                        sources: { 7: 'local', 8: 'spotify' },
                        source_ids: { 7: null, 8: 'spotify-track' },
                        spotify_uris: { 7: null, 8: 'spotify:track:abc' },
                        cursor: { after_id: 0, next_after_id: null, has_more: false, max_id: 8 },
                        pagination: { per_page: 100, total: 2, total_pages: 1 },
                    } satisfies AudioIdsResponse,
                };
            }

            if (url === '/api/audio/metadata-runs/active') {
                return {
                    data: {
                        run: {
                            ...runFixture(),
                            status: 'running',
                            processed_files: 3,
                            current_file_id: 8,
                            current_step: 'spotify',
                            current_step_label: 'Checking Spotify',
                        },
                        proposals: [],
                    },
                };
            }

            if (url === '/api/audio/metadata-runs/41') {
                runPollRequests += 1;

                return {
                    data: {
                        run: {
                            ...runFixture(),
                            status: 'running',
                            processed_files: 4,
                            current_file_id: 8,
                            current_step: 'musicbrainz',
                            current_step_label: 'Checking MusicBrainz',
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

            throw new Error(`Unexpected post URL: ${url}`);
        });

        const wrapper = await mountAudio();
        await flushPromises();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/audio/metadata-runs/active');
        expect(wrapper.text()).toContain('Metadata scan running: 3/10 files (30%).');

        vi.advanceTimersByTime(1600);
        await flushPromises();

        expect(runPollRequests).toBe(1);
        expect(wrapper.text()).toContain('Metadata scan running: 4/10 files (40%).');
    });

    it('pauses resumes and cancels an active whole-library metadata run', async () => {
        mockAxios.get.mockImplementation(async (url: string) => {
            if (url === '/api/audio/ids') {
                return {
                    data: {
                        ids: [7, 8],
                        sources: { 7: 'local', 8: 'spotify' },
                        source_ids: { 7: null, 8: 'spotify-track' },
                        spotify_uris: { 7: null, 8: 'spotify:track:abc' },
                        cursor: { after_id: 0, next_after_id: null, has_more: false, max_id: 8 },
                        pagination: { per_page: 100, total: 2, total_pages: 1 },
                    } satisfies AudioIdsResponse,
                };
            }

            if (url === '/api/audio/metadata-runs/active') {
                return {
                    data: {
                        run: {
                            ...runFixture(),
                            status: 'running',
                            processed_files: 3,
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

            if (url === '/api/audio/metadata-runs/41/pause') {
                return {
                    data: {
                        run: {
                            ...runFixture(),
                            status: 'paused',
                            processed_files: 3,
                        },
                    },
                };
            }

            if (url === '/api/audio/metadata-runs/41/resume') {
                return {
                    data: {
                        run: {
                            ...runFixture(),
                            status: 'pending',
                            processed_files: 3,
                        },
                    },
                };
            }

            if (url === '/api/audio/metadata-runs/41/cancel') {
                return {
                    data: {
                        run: {
                            ...runFixture(),
                            status: 'canceled',
                            processed_files: 3,
                            finished_at: '2026-06-12T10:00:00+04:00',
                        },
                    },
                };
            }

            throw new Error(`Unexpected post URL: ${url}`);
        });

        const wrapper = await mountAudio();
        await flushPromises();

        await wrapper.get('[data-test="audio-metadata-run-pause"]').trigger('click');
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/audio/metadata-runs/41/pause');
        expect(wrapper.text()).toContain('Metadata scan paused: 3/10 files (30%).');

        await wrapper.get('[data-test="audio-metadata-run-resume"]').trigger('click');
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/audio/metadata-runs/41/resume');
        expect(wrapper.text()).toContain('Metadata scan queued: 3/10 files (30%).');

        await wrapper.get('[data-test="audio-metadata-run-cancel"]').trigger('click');
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/audio/metadata-runs/41/cancel');
        expect(wrapper.text()).toContain('Metadata scan canceled: 3/10 files (30%).');
    });

    it('queues a whole-library metadata run and renders polled progress', async () => {
        let runPollRequests = 0;

        mockAxios.get.mockImplementation(async (url: string) => {
            if (url === '/api/audio/ids') {
                return {
                    data: {
                        ids: [7, 8],
                        sources: { 7: 'local', 8: 'spotify' },
                        source_ids: { 7: null, 8: 'spotify-track' },
                        spotify_uris: { 7: null, 8: 'spotify:track:abc' },
                        cursor: { after_id: 0, next_after_id: null, has_more: false, max_id: 8 },
                        pagination: { per_page: 100, total: 2, total_pages: 1 },
                    } satisfies AudioIdsResponse,
                };
            }

            if (url === '/api/audio/metadata-runs/active') {
                return { data: { run: null, proposals: [] } };
            }

            if (url === '/api/audio/metadata-runs/41') {
                runPollRequests += 1;

                return runPollRequests === 1
                    ? {
                        data: {
                            run: {
                                ...runFixture(),
                                status: 'running',
                                processed_files: 4,
                                current_file_id: 8,
                                current_step: 'musicbrainz',
                                current_step_label: 'Checking MusicBrainz',
                            },
                            proposals: [],
                        },
                    }
                    : {
                        data: {
                            run: {
                                ...runFixture(),
                                status: 'completed',
                                processed_files: 10,
                                proposal_count: 2,
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

            if (url === '/api/audio/metadata-runs') {
                return {
                    data: {
                        run: runFixture(),
                    },
                };
            }

            throw new Error(`Unexpected post URL: ${url}`);
        });

        const wrapper = await mountAudio();
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        await wrapper.get('[data-test="audio-metadata-library-scan-cta"]').trigger('click');
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/audio/metadata-runs', {
            scope: 'all',
            source_filter: 'all',
        });
        expect(wrapper.text()).toContain('Metadata scan queued: 0/10 files (0%).');

        vi.advanceTimersByTime(1600);
        await flushPromises();

        expect(runPollRequests).toBe(1);
        expect(wrapper.text()).toContain('Metadata scan running: 4/10 files (40%).');

        vi.advanceTimersByTime(1600);
        await flushPromises();

        expect(runPollRequests).toBe(2);
        expect(wrapper.text()).toContain('Metadata scan completed: 10/10 files (100%). 2 proposals ready.');
    });
});

function trackDetails(): AudioDetailsResponse['items'] {
    return [7, 8].map((id) => ({
        id,
        title: id === 7 ? 'Local Track' : 'Spotify Track',
        source: id === 7 ? 'local' : 'spotify',
        source_id: id === 7 ? null : 'spotify-track',
        spotify_uri: id === 7 ? null : 'spotify:track:abc',
        artists: [id === 7 ? 'Local Artist' : 'Spotify Artist'],
        albums: [id === 7 ? 'Local Album' : 'Spotify Album'],
        cover_url: null,
        duration_seconds: null,
        reaction: null,
        blacklisted_at: null,
        previewed_count: 0,
        seen_count: 0,
    }));
}
