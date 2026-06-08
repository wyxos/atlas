import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountAudio } from './audioTestUtils';
import { useGlobalAudioPlayer } from '../composables/useGlobalAudioPlayer';
import type { AudioDetailsResponse, AudioIdsResponse, AudioMetadataProposal } from '@/types/audio';

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
};

function idsResponse(): AudioIdsResponse {
    return {
        ids: [7],
        sources: { 7: 'local' },
        source_ids: { 7: null },
        spotify_uris: { 7: null },
        cursor: { after_id: 0, next_after_id: null, has_more: false, max_id: 7 },
        pagination: { per_page: 100, total: 1, total_pages: 1 },
    };
}

function detailsResponse(overrides: Partial<AudioDetailsResponse['items'][number]> = {}): AudioDetailsResponse {
    return {
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
            ...overrides,
        }],
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
            release_label: { current: null, proposed: 'Universal Republic' },
            catalog_number: { current: null, proposed: 'B0015663-02' },
            discogs_release_id: { current: null, proposed: '4647572' },
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

function manualOptionsProposalFixture(): AudioMetadataProposal {
    return {
        id: 24,
        file_id: 7,
        run_id: 11,
        provider: 'multi_source_review',
        status: 'pending',
        confidence: 90,
        current_values: {
            album: 'Current Album',
            cover_url: null,
        },
        proposed_values: {},
        changes: {},
        field_options: {
            album: [
                {
                    id: 'album-nrj-story',
                    provider: 'acoustid_musicbrainz',
                    confidence: 96,
                    value: 'NRJ Story',
                    recommended: false,
                    reason: 'The attached MusicBrainz release is ambiguous.',
                    review_verdict: 'ambiguous',
                },
                {
                    id: 'album-discovery',
                    provider: 'musicbrainz_cover_art',
                    confidence: 82,
                    value: 'Discovery',
                    recommended: true,
                    reason: null,
                    review_verdict: null,
                },
            ],
            cover_url: [{
                id: 'cover-discovery',
                provider: 'musicbrainz_cover_art',
                confidence: 82,
                value: 'http://coverartarchive.org/release/discovery/front-500.jpg',
                recommended: true,
                reason: null,
                review_verdict: null,
            }],
        },
        evidence: {
            source: 'multi_source_metadata_review',
            candidate_count: 2,
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

describe('Audio metadata review options', () => {
    it('previews current and proposed cover changes', async () => {
        mockAxios.get.mockImplementation(async (url: string) => {
            if (url === '/api/audio/ids') {
                return { data: idsResponse() };
            }

            if (url === '/api/audio/7/metadata-proposals/latest') {
                return { data: { proposal: coverProposalFixture() } };
            }

            throw new Error(`Unexpected get URL: ${url}`);
        });

        mockAxios.post.mockImplementation(async (url: string) => {
            if (url === '/api/audio/details') {
                return { data: detailsResponse({ cover_url: '/api/audio/album-covers/7' }) };
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
        expect(document.body.textContent).toContain('Universal Republic');
        expect(document.body.textContent).toContain('B0015663-02');
        expect(document.body.textContent).toContain('Discogs release');
        expect(document.body.textContent).toContain('4647572');
        expect(document.body.textContent?.replace(/\s+/g, ' ')).toContain('MusicBrainz release search / Matched artists, album / Release details / Cover Art Archive / Data provided by Discogs');
    });

    it('renders manual provider options and applies the selected option ids', async () => {
        mockAxios.get.mockImplementation(async (url: string) => {
            if (url === '/api/audio/ids') {
                return { data: idsResponse() };
            }

            if (url === '/api/audio/7/metadata-proposals/latest') {
                return { data: { proposal: manualOptionsProposalFixture() } };
            }

            throw new Error(`Unexpected get URL: ${url}`);
        });

        mockAxios.post.mockImplementation(async (url: string) => {
            if (url === '/api/audio/details') {
                return {
                    data: detailsResponse({
                        title: 'One More Time',
                        artists: ['Daft Punk'],
                        albums: ['Current Album'],
                        duration_seconds: 323,
                    }),
                };
            }

            throw new Error(`Unexpected post URL: ${url}`);
        });

        mockAxios.patch.mockResolvedValue({
            data: {
                proposal: { ...manualOptionsProposalFixture(), status: 'applied' },
            },
        });

        const wrapper = await mountAudio();
        await flushPromises();
        vi.advanceTimersByTime(180);
        await flushPromises();

        await wrapper.get('[data-test="audio-track-title"]').trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Multi-source review - 90%');
        expect(document.body.textContent).toContain('Multiple metadata candidates');
        expect(document.body.textContent).toContain('NRJ Story');
        expect(document.body.textContent).toContain('Discovery');

        const albumOptions = document.body.querySelectorAll<HTMLInputElement>('[data-test="audio-metadata-field-option-album"] input[type="radio"]');
        expect(albumOptions).toHaveLength(2);
        albumOptions[0].click();
        await flushPromises();

        (document.body.querySelector('[data-test="audio-metadata-apply"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(mockAxios.patch).toHaveBeenCalledWith('/api/audio/metadata-proposals/24', {
            action: 'apply',
            fields: ['album', 'cover_url'],
            field_options: {
                album: 'album-nrj-story',
                cover_url: 'cover-discovery',
            },
        });
    });
});
