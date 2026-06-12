import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AudioMetadataProposalTable from '@/components/AudioMetadataProposalTable.vue';
import type { AudioMetadataProposal } from '@/types/audio';

function proposalFixture(): AudioMetadataProposal {
    return {
        id: 26,
        file_id: 7,
        run_id: 11,
        provider: 'acoustid_musicbrainz',
        status: 'pending',
        confidence: 96,
        current_values: {
            album: 'Tron: Legacy (Cd2)',
            track_number: null,
        },
        proposed_values: {},
        changes: {},
        field_options: {
            album: [{
                id: 'album-musicbrainz',
                provider: 'acoustid_musicbrainz',
                confidence: 96,
                value: 'TRON: Legacy: Original Motion Picture Score: Recording Sessions',
                recommended: false,
                reason: 'The source release has different edition context, so keep it manual.',
                review_verdict: 'ambiguous',
                source_label: 'MusicBrainz release',
                source_url: 'https://musicbrainz.org/release/recording-sessions',
            }],
            track_number: [{
                id: 'track-musicbrainz',
                provider: 'acoustid_musicbrainz',
                confidence: 96,
                value: '6',
                recommended: false,
                reason: 'Track number depends on the unresolved release edition.',
                review_verdict: 'ambiguous',
                source_label: 'MusicBrainz release',
                source_url: 'https://musicbrainz.org/release/recording-sessions',
            }],
        },
        evidence: {},
        created_at: null,
        reviewed_at: null,
        applied_at: null,
        ignored_at: null,
    };
}

describe('AudioMetadataProposalTable', () => {
    it('renders raw provider options without AI review notes', () => {
        const wrapper = mount(AudioMetadataProposalTable, {
            props: {
                proposal: proposalFixture(),
                fields: ['album', 'track_number'],
                selectedFields: [],
                selectedFieldOptions: {},
            },
        });

        expect(wrapper.text()).toContain('TRON: Legacy: Original Motion Picture Score: Recording Sessions');
        expect(wrapper.text()).toContain('MusicBrainz release');
        expect(wrapper.text()).not.toContain('Recommended');
        expect(wrapper.text()).not.toContain('AI marked this field ambiguous');
        expect(wrapper.find('[data-test="audio-metadata-option-note"]').exists()).toBe(false);
    });

    it('does not show old recommendation flags as badges', () => {
        const proposal = proposalFixture() as unknown as AudioMetadataProposal;

        proposal.field_options = {
            album: [{
                id: 'album-musicbrainz',
                provider: 'acoustid_musicbrainz',
                confidence: 96,
                value: 'Discovery',
                recommended: true,
                reason: null,
                review_verdict: null,
                source_label: 'MusicBrainz release',
                source_url: 'https://musicbrainz.org/release/discovery',
            }],
        };

        const wrapper = mount(AudioMetadataProposalTable, {
            props: {
                proposal,
                fields: ['album'],
                selectedFields: [],
                selectedFieldOptions: {},
            },
        });

        expect(wrapper.text()).toContain('Discovery');
        expect(wrapper.text()).not.toContain('Recommended');
    });
});
