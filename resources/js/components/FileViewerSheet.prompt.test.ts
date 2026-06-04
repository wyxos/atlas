import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import FileViewerSheet from './FileViewerSheet.vue';
import type { File } from '@/types/file';

function makeFile(overrides: Partial<File> = {}): File {
    return {
        id: 1,
        source: 'Local',
        source_id: null,
        filename: 'test.jpg',
        ext: 'jpg',
        size: 1024,
        width: null,
        height: null,
        mime_type: 'image/jpeg',
        hash: null,
        title: null,
        description: null,
        url: null,
        file_url: null,
        referrer_url: null,
        path: 'downloads/test.jpg',
        absolute_path: null,
        absolute_preview_path: null,
        preview_url: null,
        cover_url: null,
        disk_url: null,
        preview_file_url: null,
        poster_url: null,
        preview_path: null,
        poster_path: null,
        tags: null,
        parent_id: null,
        chapter: null,
        previewed_at: null,
        previewed_count: 0,
        seen_at: null,
        seen_count: 0,
        auto_blacklisted: false,
        blacklisted_at: null,
        downloaded: true,
        downloaded_at: null,
        imported_at: null,
        download_progress: 0,
        not_found: false,
        listing_metadata: null,
        detail_metadata: null,
        metadata: null,
        containers: [],
        capabilities: {
            refresh_source_media: false,
            watch_source_and_refresh: false,
            unwatch_source_account: false,
        },
        created_at: '2024-01-01T00:00:00Z',
        updated_at: '2024-01-01T00:00:00Z',
        ...overrides,
    };
}

describe('FileViewerSheet prompt state', () => {
    it('renders prompt moderation and auto-blacklist provenance in the prompt danger card', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: null,
                isLoading: false,
                fileData: makeFile({
                    id: 77,
                    auto_blacklisted: true,
                    blacklisted_at: '2026-05-01T10:00:00Z',
                    prompt_moderation_rule: {
                        id: 4,
                        name: 'Prompt rule',
                        action_type: 'blacklist',
                        matched_terms: ['term one', 'term two'],
                        reason: 'Matched prompt terms: term one, term two',
                        blacklist_previewed_count_mode: 'feed_removed',
                    },
                    auto_blacklist_rule: {
                        id: 4,
                        name: 'Prompt rule',
                        action_type: 'blacklist',
                        matched_terms: ['term one'],
                        reason: 'Matched prompt terms: term one',
                        blacklist_previewed_count_mode: 'feed_removed',
                    },
                    blacklist_rule: {
                        id: 4,
                        name: 'Prompt rule',
                        action_type: 'blacklist',
                        matched_terms: ['term one'],
                        reason: 'Matched prompt terms: term one',
                        blacklist_previewed_count_mode: 'feed_removed',
                    },
                    auto_blacklist_containers: [
                        {
                            id: 12,
                            type: 'User',
                            source: 'deviantart.com',
                            source_id: 'artist',
                            referrer: 'https://www.deviantart.com/artist',
                            blacklisted: true,
                            blacklisted_at: '2026-05-01T09:00:00Z',
                            action_type: 'blacklist',
                            blacklist_previewed_count_mode: 'preserve',
                            file_stats: {
                                unreacted: 1,
                                blacklisted: 2,
                                positive: 3,
                            },
                        },
                    ],
                }),
                prompt: 'term one and term two are present',
                showPrompt: true,
            },
        });

        const text = wrapper.text();
        const moderationCard = wrapper.get('[data-test="file-prompt-moderation-card"]');

        expect(text).toContain('# 77');
        expect(moderationCard.text()).toContain('Auto blacklist');
        expect(moderationCard.text()).toContain('Auto blacklist rule');
        expect(moderationCard.text()).toContain('#4 Prompt rule (blacklist)');
        expect(moderationCard.text()).toContain('Terms: term one');
        expect(moderationCard.text()).toContain('Concerned container');
        expect(moderationCard.text()).toContain('#12 User');
        expect(moderationCard.text()).toContain('deviantart.com');
        expect(moderationCard.text()).toContain('artist');
        expect(text).not.toContain('Moderation Rule (Flagged)');
        expect(text).not.toContain('Moderation Rule (Matched)');
    });

    it('uses the persisted blacklist rule in the prompt danger card when active prompt match details are absent', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: null,
                isLoading: false,
                fileData: makeFile({
                    id: 161323,
                    auto_blacklisted: true,
                    blacklisted_at: '2026-05-30T10:00:00Z',
                    blacklist_rule: {
                        id: 24,
                        name: 'Fat',
                        action_type: 'blacklist',
                        matched_terms: ['fat'],
                        reason: 'Matched prompt terms: fat',
                        blacklist_previewed_count_mode: 'preserve',
                    },
                }),
                prompt: 'character with fat body type',
                showPrompt: true,
            },
        });

        const text = wrapper.text();
        const moderationCard = wrapper.get('[data-test="file-prompt-moderation-card"]');

        expect(moderationCard.text()).toContain('Auto blacklist rule');
        expect(moderationCard.text()).toContain('#24 Fat (blacklist)');
        expect(moderationCard.text()).toContain('Terms: fat');
        expect(moderationCard.text()).toContain('Matched prompt terms: fat');
        expect(text).not.toContain('Moderation Rule (Matched)');
    });

    it('explains preview-count auto blacklists when no rule or container is attached', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: null,
                isLoading: false,
                fileData: makeFile({
                    id: 2308,
                    auto_blacklisted: true,
                    blacklisted_at: '2026-05-30T07:11:02Z',
                    previewed_count: 2,
                }),
                showPrompt: true,
            },
        });

        const text = wrapper.text();
        const moderationCard = wrapper.get('[data-test="file-prompt-moderation-card"]');
        const previewCountProvenance = wrapper.get('[data-test="file-prompt-preview-count-blacklist"]');

        expect(moderationCard.text()).toContain('Auto blacklist');
        expect(moderationCard.text()).toContain('Likely preview threshold');
        expect(previewCountProvenance.text()).toContain('Preview count auto blacklist');
        expect(previewCountProvenance.text()).toContain('No moderation rule or blacklisted container is attached.');
        expect(previewCountProvenance.text()).toContain('Previewed 2 times.');
        expect(text).not.toContain('Moderation Rule (Matched)');
    });

    it('describes terminal preview-count auto blacklists without showing the sentinel as literal views', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: null,
                isLoading: false,
                fileData: makeFile({
                    id: 2308,
                    auto_blacklisted: true,
                    blacklisted_at: '2026-05-30T07:11:02Z',
                    previewed_count: 100000,
                }),
                showPrompt: true,
            },
        });

        const previewCountProvenance = wrapper.get('[data-test="file-prompt-preview-count-blacklist"]');

        expect(previewCountProvenance.text()).toContain('Reached the feed-removal threshold.');
        expect(previewCountProvenance.text()).not.toContain('Previewed 100000 times.');
    });

    it('shows prompt loading state in the sheet', () => {
        const wrapper = mount(FileViewerSheet, {
            props: {
                isOpen: true,
                fileId: 1,
                isLoading: false,
                fileData: makeFile(),
                isPromptLoading: true,
                showPrompt: true,
            },
        });

        expect(wrapper.get('[data-test="file-prompt"]').text()).toContain('Loading prompt...');
    });
});
