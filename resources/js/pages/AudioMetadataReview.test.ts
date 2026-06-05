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
        current_file_id: null,
        current_step: null,
        current_step_label: null,
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
        provider: 'musicbrainz_discogs',
        status: 'pending',
        confidence: 84,
        current_values: {
            cover_url: '/api/audio/album-covers/7',
            release_label: null,
            catalog_number: null,
        },
        proposed_values: {
            cover_url: 'http://coverartarchive.org/release/release-mbid/front-500.jpg',
            release_label: 'Universal Republic',
            catalog_number: 'B0015663-02',
            discogs_release_id: '4647572',
        },
        changes: {
            release_label: {
                current: null,
                proposed: 'Universal Republic',
            },
            catalog_number: {
                current: null,
                proposed: 'B0015663-02',
            },
            discogs_release_id: {
                current: null,
                proposed: '4647572',
            },
            cover_url: {
                current: '/api/audio/album-covers/7',
                proposed: 'http://coverartarchive.org/release/release-mbid/front-500.jpg',
            },
        },
        evidence: {
            source: 'musicbrainz_release_search',
            matched_existing_fields: ['artists', 'album'],
            release_detail_source: 'musicbrainz_release_lookup',
            cover_source: 'cover_art_archive',
            discogs_release_id: '4647572',
            discogs_release_url: 'https://www.discogs.com/release/4647572',
            discogs_source: 'discogs_release_search',
        },
        created_at: null,
        reviewed_at: null,
        applied_at: null,
        ignored_at: null,
    };
}

function aliasProposalFixture(): AudioMetadataProposal {
    return {
        id: 23,
        file_id: 7,
        run_id: 11,
        provider: 'acoustid_musicbrainz_ai_discogs',
        status: 'pending',
        confidence: 96,
        current_values: {
            title: 'Theme from GTO',
            title_aliases: [],
            album: 'GTO TV Animation Original Soundtrack',
            album_aliases: [],
        },
        proposed_values: {
            title: 'The Theme From GTO',
            title_aliases: ['Theme from GTO'],
            album: 'TVアニメーション GTO オリジナルサウンドトラック',
            album_aliases: ['TV Animation GTO Original Soundtrack'],
        },
        changes: {
            title: {
                current: 'Theme from GTO',
                proposed: 'The Theme From GTO',
            },
            title_aliases: {
                current: [],
                proposed: ['Theme from GTO'],
            },
            album: {
                current: 'GTO TV Animation Original Soundtrack',
                proposed: 'TVアニメーション GTO オリジナルサウンドトラック',
            },
            album_aliases: {
                current: [],
                proposed: ['TV Animation GTO Original Soundtrack'],
            },
        },
        evidence: {
            source: 'acoustid_fingerprint',
            acoustid_score: 99.6,
            musicbrainz_recording_id: 'bike-recording-mbid',
            matched_existing_fields: ['duration', 'title'],
            discogs_release_id: '17124567',
            discogs_release_url: 'https://www.discogs.com/release/17124567',
            discogs_source: 'discogs_release_search',
            ai_review: {
                verdict: 'accept',
                confidence: 0.88,
                reason: 'The current title is an English alias.',
                model: 'qwen-test',
            },
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
        expect(document.body.textContent).toContain('MusicBrainz / Discogs - 84%');
        expect(document.body.textContent).toContain('Label');
        expect(document.body.textContent).toContain('Universal Republic');
        expect(document.body.textContent).toContain('Catalog #');
        expect(document.body.textContent).toContain('B0015663-02');
        expect(document.body.textContent).toContain('Discogs release');
        expect(document.body.textContent).toContain('4647572');
        expect(document.body.textContent?.replace(/\s+/g, ' ')).toContain('MusicBrainz release search / Matched artists, album / Release details / Cover Art Archive / Data provided by Discogs');
    });

    it('polls queued single metadata runs and updates the sheet with progress', async () => {
        let runPollRequests = 0;

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

            if (url === '/api/audio/metadata-runs/11') {
                runPollRequests += 1;

                return runPollRequests === 1
                    ? {
                        data: {
                            run: {
                                ...runFixture(),
                                status: 'running',
                                processed_files: 0,
                                proposal_count: 0,
                                current_file_id: 7,
                                current_step: 'discogs',
                                current_step_label: 'Searching Discogs release data',
                            },
                            proposals: [],
                        },
                    }
                    : { data: { run: runFixture(), proposals: [proposalFixture()] } };
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
                        run: {
                            ...runFixture(),
                            status: 'pending',
                            processed_files: 0,
                            proposal_count: 0,
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

        await wrapper.get('[data-test="audio-track-title"]').trigger('click');
        await flushPromises();

        (document.body.querySelector('[data-test="audio-track-metadata-run"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(document.body.textContent).toContain('Metadata scan queued.');

        vi.advanceTimersByTime(1600);
        await flushPromises();

        expect(runPollRequests).toBe(1);
        expect(document.body.textContent).toContain('Searching Discogs release data');

        vi.advanceTimersByTime(1600);
        await flushPromises();

        expect(runPollRequests).toBe(2);
        expect(document.body.textContent).toContain('Metadata proposal ready for review.');
        expect(document.body.textContent).toContain('Tagged Track');
    });

    it('hides stale metadata alias fields and applies canonical fields only', async () => {
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
                return { data: { proposal: aliasProposalFixture() } };
            }

            throw new Error(`Unexpected get URL: ${url}`);
        });

        mockAxios.post.mockImplementation(async (url: string) => {
            if (url === '/api/audio/details') {
                return {
                    data: {
                        items: [{
                            id: 7,
                            title: 'Theme from GTO',
                            source: 'local',
                            source_id: null,
                            spotify_uri: null,
                            artists: ['Yusuke Honma'],
                            albums: ['GTO TV Animation Original Soundtrack'],
                            cover_url: null,
                            duration_seconds: 201,
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

        mockAxios.patch.mockResolvedValue({
            data: {
                proposal: { ...aliasProposalFixture(), status: 'applied' },
            },
        });

        const wrapper = await mountAudio();
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        await wrapper.get('[data-test="audio-track-title"]').trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('AcoustID / MusicBrainz / AI / Discogs - 96%');
        expect(document.body.textContent).not.toContain('Title aliases');
        expect(document.body.textContent).toContain('Theme from GTO');
        expect(document.body.textContent).not.toContain('Album aliases');
        expect(document.body.textContent).toContain('TVアニメーション GTO オリジナルサウンドトラック');
        expect(document.body.textContent).not.toContain('TV Animation GTO Original Soundtrack');

        (document.body.querySelector('[data-test="audio-metadata-apply"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(mockAxios.patch).toHaveBeenCalledWith('/api/audio/metadata-proposals/23', {
            action: 'apply',
            fields: ['title', 'album'],
        });
    });

});
