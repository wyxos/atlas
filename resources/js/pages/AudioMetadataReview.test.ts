import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountAudio } from './audioTestUtils';
import { useGlobalAudioPlayer } from '../composables/useGlobalAudioPlayer';
import type { AudioDetailsResponse, AudioIdsResponse, AudioMetadataProposal, AudioMetadataRun } from '@/types/audio';

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
        proposal_count: 1,
        failed_files: 0,
        error: null,
        created_at: null,
        started_at: null,
        finished_at: null,
    };
}

function proposalFixture(status: AudioMetadataProposal['status'] = 'pending'): AudioMetadataProposal {
    return {
        id: 21,
        file_id: 7,
        run_id: 11,
        provider: 'acoustid_musicbrainz',
        status,
        confidence: 93,
        current_values: {},
        proposed_values: {
            title: 'Tagged Track',
        },
        changes: {
            title: {
                current: 'Original Track',
                proposed: 'Tagged Track',
            },
        },
        evidence: {
            source: 'acoustid_fingerprint',
            acoustid_score: 91,
            musicbrainz_recording_id: 'recording-mbid',
            duration_delta_seconds: 1,
        },
        created_at: null,
        reviewed_at: null,
        applied_at: null,
        ignored_at: null,
    };
}

function coverProposalFixture(): AudioMetadataProposal {
    return {
        id: 22,
        file_id: 7,
        run_id: 11,
        provider: 'musicbrainz_cover_art',
        status: 'pending',
        confidence: 82,
        current_values: {
            cover_url: '/api/audio/album-covers/7',
        },
        proposed_values: {
            cover_url: 'http://coverartarchive.org/release/release-mbid/front-500.jpg',
        },
        changes: {
            cover_url: {
                current: '/api/audio/album-covers/7',
                proposed: 'http://coverartarchive.org/release/release-mbid/front-500.jpg',
            },
        },
        evidence: {
            source: 'musicbrainz_release_search',
            matched_existing_fields: ['artists', 'album'],
            cover_source: 'cover_art_archive',
        },
        created_at: null,
        reviewed_at: null,
        applied_at: null,
        ignored_at: null,
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

describe('Audio metadata review', () => {
    it('opens track details from the title and reviews a metadata proposal', async () => {
        let metadataRunRequests = 0;

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
                metadataRunRequests += 1;

                return metadataRunRequests === 1
                    ? { data: { run: runFixture(), proposal: proposalFixture() } }
                    : { data: { run: { ...runFixture(), proposal_count: 0 }, proposal: null } };
            }

            throw new Error(`Unexpected post URL: ${url}`);
        });

        mockAxios.patch.mockResolvedValue({
            data: {
                proposal: proposalFixture('applied'),
            },
        });

        const wrapper = await mountAudio();
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        await wrapper.get('[data-test="audio-track-title"]').trigger('click');
        await flushPromises();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/audio/7/metadata-proposals/latest');
        expect(document.body.textContent).toContain('Original Track');
        expect(document.body.textContent).toContain('No pending proposal');

        (document.body.querySelector('[data-test="audio-track-metadata-run"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/audio/7/metadata-runs');
        expect(document.body.textContent).toContain('Tagged Track');
        expect(document.body.textContent).toContain('AcoustID / MusicBrainz - 93%');
        expect(document.body.textContent).toContain('Fingerprint 91% / MusicBrainz recording / Duration delta 1s');

        (document.body.querySelector('[data-test="audio-metadata-apply"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(mockAxios.patch).toHaveBeenCalledWith('/api/audio/metadata-proposals/21', {
            action: 'apply',
            fields: ['title'],
        });
        expect(document.body.textContent).toContain('Metadata applied.');
        expect(document.body.textContent).not.toContain('Apply selected');
        expect(document.body.textContent).not.toContain('AcoustID / MusicBrainz - 93%');

        (document.body.querySelector('[data-test="audio-track-metadata-run"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(metadataRunRequests).toBe(2);
        expect(document.body.textContent).toContain('No metadata changes found.');
        expect(document.body.textContent).toContain('No pending proposal');
        expect(document.body.textContent).not.toContain('Apply selected');
    });

    it('previews current and proposed cover changes', async () => {
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
                return { data: { proposal: coverProposalFixture() } };
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
                            cover_url: '/api/audio/album-covers/7',
                            duration_seconds: null,
                            reaction: null,
                            blacklisted_at: null,
                            previewed_count: 0,
                            seen_count: 0,
                        }],
                    } satisfies AudioDetailsResponse,
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

        const currentCover = document.body.querySelector('[data-test="audio-metadata-cover-current"]');
        const proposedCover = document.body.querySelector('[data-test="audio-metadata-cover-proposed"]');

        expect(currentCover).toBeInstanceOf(HTMLImageElement);
        expect(proposedCover).toBeInstanceOf(HTMLImageElement);
        expect(currentCover?.getAttribute('src')).toBe('/api/audio/album-covers/7');
        expect(proposedCover?.getAttribute('src')).toBe('https://coverartarchive.org/release/release-mbid/front-500.jpg');
        expect(document.body.textContent).toContain('MusicBrainz Cover Art - 82%');
        expect(document.body.textContent).toContain('MusicBrainz release search / Matched artists, album / Cover Art Archive');
    });
});
