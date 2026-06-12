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

function repeatedCandidateReasonProposalFixture(): AudioMetadataProposal {
    const candidateReason = 'The proposed release looks like a different edition and should be reviewed as a source-level decision.';

    return {
        id: 25,
        file_id: 7,
        run_id: 11,
        provider: 'acoustid_musicbrainz',
        status: 'pending',
        confidence: 96,
        current_values: {
            duration_seconds: 233,
            release_date: null,
            release_country: null,
        },
        proposed_values: {},
        changes: {},
        field_options: {
            duration_seconds: [{
                id: 'duration-musicbrainz',
                provider: 'acoustid_musicbrainz',
                confidence: 96,
                value: 232,
                recommended: false,
                reason: candidateReason,
                review_verdict: 'ambiguous',
                source_label: 'MusicBrainz release',
                source_url: 'https://musicbrainz.org/release/recording-sessions',
            }],
            release_date: [{
                id: 'release-date-musicbrainz',
                provider: 'acoustid_musicbrainz',
                confidence: 96,
                value: '2012',
                recommended: false,
                reason: candidateReason,
                review_verdict: 'ambiguous',
                source_label: 'MusicBrainz release',
                source_url: 'https://musicbrainz.org/release/recording-sessions',
            }],
            release_country: [{
                id: 'country-musicbrainz',
                provider: 'acoustid_musicbrainz',
                confidence: 96,
                value: 'XW',
                recommended: false,
                reason: candidateReason,
                review_verdict: 'ambiguous',
                source_label: 'MusicBrainz release',
                source_url: 'https://musicbrainz.org/release/recording-sessions',
            }],
        },
        evidence: {
            source: 'acoustid_fingerprint',
            field_review: {
                verdict: 'ambiguous',
                reason: candidateReason,
            },
            musicbrainz_release_id: 'recording-sessions',
        },
        created_at: null,
        reviewed_at: null,
        applied_at: null,
        ignored_at: null,
    };
}

function countOccurrences(haystack: string, needle: string): number {
    return haystack.split(needle).length - 1;
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

it('does not show removed AI candidate reasoning from stale proposal payloads', async () => {
    const proposal = repeatedCandidateReasonProposalFixture();
    const candidateReason = String((proposal.evidence.field_review as { reason: string }).reason);

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
                    title: 'Encom Part I',
                    artists: ['Daft Punk'],
                    albums: ['Tron: Legacy (Cd2)'],
                    duration_seconds: 233,
                }),
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

    expect(document.body.querySelector('[data-test="audio-metadata-proposal-ai-review"]')).toBeNull();
    expect(countOccurrences(document.body.textContent ?? '', candidateReason)).toBe(0);
    expect(document.body.querySelectorAll('[data-test="audio-metadata-option-note"]')).toHaveLength(0);
    expect(document.body.textContent).toContain('232');
    expect(document.body.textContent).toContain('2012');
    expect(document.body.textContent).toContain('XW');
});
