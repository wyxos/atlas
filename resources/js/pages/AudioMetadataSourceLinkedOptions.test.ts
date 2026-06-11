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

function sourceLinkedOptionsProposalFixture(): AudioMetadataProposal {
    const nrjSourceUrl = 'https://musicbrainz.org/release/nrj-story-release-mbid';

    return {
        id: 24,
        file_id: 7,
        run_id: 11,
        provider: 'multi_source_review',
        status: 'pending',
        confidence: 90,
        current_values: {
            album: 'Current Album',
            track_number: null,
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
                    reason: null,
                    review_verdict: null,
                    source_label: 'MusicBrainz release',
                    source_url: nrjSourceUrl,
                },
                {
                    id: 'album-discovery',
                    provider: 'musicbrainz_cover_art',
                    confidence: 82,
                    value: 'Discovery',
                    recommended: true,
                    reason: null,
                    review_verdict: null,
                    source_label: 'MusicBrainz release',
                    source_url: 'https://musicbrainz.org/release/discovery-release-mbid',
                },
            ],
            track_number: [{
                id: 'track-nrj-story',
                provider: 'acoustid_musicbrainz',
                confidence: 96,
                value: '5',
                recommended: false,
                reason: null,
                review_verdict: null,
                source_label: 'MusicBrainz release',
                source_url: nrjSourceUrl,
            }],
            cover_url: [
                {
                    id: 'cover-discovery',
                    provider: 'musicbrainz_cover_art',
                    confidence: 82,
                    value: 'http://coverartarchive.org/release/discovery/front-500.jpg',
                    recommended: true,
                    reason: null,
                    review_verdict: null,
                    source_label: 'MusicBrainz release',
                    source_url: 'https://musicbrainz.org/release/discovery-release-mbid',
                },
                {
                    id: 'cover-nrj-story-front',
                    provider: 'acoustid_musicbrainz',
                    confidence: 96,
                    value: 'https://coverartarchive.org/release/nrj-story/front-500.jpg',
                    recommended: false,
                    reason: null,
                    review_verdict: null,
                    source_label: 'MusicBrainz release',
                    source_url: nrjSourceUrl,
                },
                {
                    id: 'cover-nrj-story-alt',
                    provider: 'acoustid_musicbrainz',
                    confidence: 96,
                    value: 'https://coverartarchive.org/release/nrj-story/alt-500.jpg',
                    recommended: false,
                    reason: null,
                    review_verdict: null,
                    source_label: 'MusicBrainz release',
                    source_url: nrjSourceUrl,
                },
            ],
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

it('selects unique field options from the same source link', async () => {
    const proposal = sourceLinkedOptionsProposalFixture();

    mockAxios.get.mockImplementation(async (url: string) => {
        if (url === '/api/audio/ids') {
            return { data: idsResponse() };
        }

        if (url === '/api/audio/7/metadata-proposals/latest') {
            return { data: { proposal } };
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
            proposal: { ...proposal, status: 'applied' },
        },
    });

    const wrapper = await mountAudio();
    await flushPromises();
    vi.advanceTimersByTime(180);
    await flushPromises();

    await wrapper.get('[data-test="audio-track-title"]').trigger('click');
    await flushPromises();

    const coverOptions = document.body.querySelectorAll<HTMLInputElement>('[data-test="audio-metadata-field-option-cover_url"] input[type="radio"]');
    expect(coverOptions).toHaveLength(3);
    expect(coverOptions[0].checked).toBe(true);

    coverOptions[2].click();
    await flushPromises();

    const albumOptions = document.body.querySelectorAll<HTMLInputElement>('[data-test="audio-metadata-field-option-album"] input[type="radio"]');
    const trackOptions = document.body.querySelectorAll<HTMLInputElement>('[data-test="audio-metadata-field-option-track_number"] input[type="radio"]');
    expect(albumOptions[0].checked).toBe(true);
    expect(trackOptions[0].checked).toBe(true);
    expect(coverOptions[2].checked).toBe(true);

    (document.body.querySelector('[data-test="audio-metadata-apply"]') as HTMLButtonElement).click();
    await flushPromises();

    expect(mockAxios.patch).toHaveBeenCalledWith('/api/audio/metadata-proposals/24', {
        action: 'apply',
        fields: ['album', 'track_number', 'cover_url'],
        field_options: {
            album: 'album-nrj-story',
            track_number: 'track-nrj-story',
            cover_url: 'cover-nrj-story-alt',
        },
    });
});
