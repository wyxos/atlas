import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, nextTick } from 'vue';
import TabFilter from './TabFilter.vue';
import { BrowseFormKey, createBrowseForm } from '@/composables/useBrowseForm';
import type { TabData } from '@/composables/useTabs';
import { LOCAL_TAB_FILTER_PRESET_GROUPS, LOCAL_TAB_FILTER_PRESETS } from '@/utils/tabFilter';
import { FEED_REMOVED_MAX_VISIBLE_PREVIEW_COUNT } from '@/lib/feedModeration';

const Stub = defineComponent({
    template: '<div><slot /></div>',
});

describe('TabFilter', () => {
    it('provides random newest and oldest presets for each local reaction view', () => {
        const reactionsGroup = LOCAL_TAB_FILTER_PRESET_GROUPS.find((group) => group.label === 'Reactions');

        expect(reactionsGroup?.presets.map((preset) => preset.label)).toEqual([
            'Reacted (Random)',
            'Reacted (Newest)',
            'Reacted (Oldest)',
            'Favorite (Random)',
            'Favorite (Newest)',
            'Favorite (Oldest)',
            'Likes (Random)',
            'Likes (Newest)',
            'Likes (Oldest)',
            'Funny (Random)',
            'Funny (Newest)',
            'Funny (Oldest)',
        ]);

        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'like_random')?.filters).toMatchObject({
            reaction_mode: 'types',
            reaction: ['like'],
            sort: 'random',
        });
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'like_newest')?.filters).toMatchObject({
            reaction_mode: 'types',
            reaction: ['like'],
            sort: 'reaction_at',
        });
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'like_oldest')?.filters).toMatchObject({
            reaction_mode: 'types',
            reaction: ['like'],
            sort: 'reaction_at_asc',
        });
    });

    it('provides newest and oldest presets for blacklisted local files', () => {
        const moderationGroup = LOCAL_TAB_FILTER_PRESET_GROUPS.find((group) => group.label === 'Moderation');

        expect(moderationGroup?.presets.map((preset) => preset.label)).toEqual([
            'Blacklisted (Newest)',
            'Blacklisted (Oldest)',
        ]);
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'blacklisted_any')?.filters).toMatchObject({
            blacklisted: 'yes',
            max_previewed_count: FEED_REMOVED_MAX_VISIBLE_PREVIEW_COUNT,
            sort: 'blacklisted_at',
        });
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'blacklisted_oldest')?.filters).toMatchObject({
            blacklisted: 'yes',
            max_previewed_count: FEED_REMOVED_MAX_VISIBLE_PREVIEW_COUNT,
            sort: 'blacklisted_at_asc',
        });
    });

    it('provides not found anomaly presets', () => {
        const anomaliesGroup = LOCAL_TAB_FILTER_PRESET_GROUPS.find((group) => group.label === 'Anomalies');

        expect(anomaliesGroup?.presets.map((preset) => preset.label)).toEqual([
            'Not Found',
            'Not Found (Reacted)',
            'Saved Blacklisted',
        ]);
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'not_found')?.filters).toMatchObject({
            not_found: 'yes',
            reaction_mode: 'any',
            blacklisted: 'no',
            sort: 'updated_at',
        });
        expect(LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === 'not_found_reacted')?.filters).toMatchObject({
            not_found: 'yes',
            reaction_mode: 'reacted',
            blacklisted: 'no',
            sort: 'reaction_at',
        });
    });

    it('prefills civitai username from tab params after sync', async () => {
        const form = createBrowseForm();

        const tab: TabData = {
            id: 10,
            label: 'CivitAI User',
            position: 0,
            isActive: true,
            params: {
                service: 'civit-ai-images',
                feed: 'online',
                source: 'all',
                page: 1,
                limit: 20,
                username: 'atlasUser',
                serviceFiltersByKey: {
                    'civit-ai-images': {
                        page: 1,
                        limit: 20,
                    },
                },
            } as any,
        };

        const wrapper = mount(TabFilter, {
            props: {
                open: true,
                availableServices: [
                    {
                        key: 'civit-ai-images',
                        label: 'CivitAI Images',
                        defaults: {},
                        schema: {
                            fields: [
                                { uiKey: 'page', serviceKey: 'page', type: 'hidden', label: 'Page' },
                                { uiKey: 'limit', serviceKey: 'limit', type: 'number', label: 'Limit' },
                                {
                                    uiKey: 'username',
                                    serviceKey: 'username',
                                    type: 'text',
                                    label: 'Username',
                                    placeholder: 'Filter to images from a specific user (e.g. someUser).',
                                },
                                {
                                    uiKey: 'postId',
                                    serviceKey: 'postId',
                                    type: 'number',
                                    label: 'Post ID',
                                    placeholder: 'The ID of a post to get images from.',
                                },
                            ],
                        },
                    },
                ],
                masonry: null,
                localDef: null,
            },
            global: {
                provide: {
                    [BrowseFormKey as symbol]: form,
                },
                stubs: {
                    Sheet: Stub,
                    SheetContent: Stub,
                    SheetHeader: Stub,
                    SheetTitle: Stub,
                    SheetTrigger: Stub,
                    SheetFooter: Stub,
                    Select: Stub,
                    SelectContent: Stub,
                    SelectItem: Stub,
                    SelectTrigger: Stub,
                    SelectValue: Stub,
                    RadioGroup: Stub,
                    RadioGroupItem: Stub,
                    Switch: Stub,
                    Checkbox: Stub,
                    Button: Stub,
                },
            },
        });

        await nextTick();
        form.syncFromTab(tab);
        await nextTick();

        const input = wrapper.find('input[placeholder="Filter to images from a specific user (e.g. someUser)."]');
        expect((input.element as HTMLInputElement).value).toBe('atlasUser');
        expect(input.classes()).toContain('text-twilight-indigo-100');
    });

    it('restores local preset label from persisted service filters', async () => {
        const form = createBrowseForm();

        const tab: TabData = {
            id: 11,
            label: 'Local Tab',
            position: 0,
            isActive: true,
            params: {
                feed: 'local',
                source: 'all',
                page: 50,
                limit: 100,
                local_preset: 'inbox_newest',
            } as any,
        };

        form.syncFromTab(tab);

        const wrapper = mount(TabFilter, {
            props: {
                open: true,
                availableServices: [],
                localDef: {
                    key: 'local',
                    label: 'Local Files',
                    defaults: {},
                    schema: {
                        fields: [
                            { uiKey: 'page', serviceKey: 'page', type: 'hidden', label: 'Page' },
                            { uiKey: 'limit', serviceKey: 'limit', type: 'number', label: 'Limit' },
                            {
                                uiKey: 'source',
                                serviceKey: 'source',
                                type: 'select',
                                label: 'Source',
                                options: [{ label: 'All', value: 'all' }],
                            },
                        ],
                    },
                },
                masonry: null,
            },
            global: {
                provide: {
                    [BrowseFormKey as symbol]: form,
                },
                stubs: {
                    Sheet: Stub,
                    SheetContent: Stub,
                    SheetHeader: Stub,
                    SheetTitle: Stub,
                    SheetTrigger: Stub,
                    SheetFooter: Stub,
                    Select: Stub,
                    SelectContent: Stub,
                    SelectItem: Stub,
                    SelectTrigger: Stub,
                    SelectValue: Stub,
                    RadioGroup: Stub,
                    RadioGroupItem: Stub,
                    Switch: Stub,
                    Checkbox: Stub,
                    Button: Stub,
                },
            },
        });

        await nextTick();

        expect(wrapper.text()).toContain('Unreacted (Newest)');
        expect(form.data.serviceFilters.local_preset).toBe('inbox_newest');
    });

    it('includes blacklist-only local preset options', async () => {
        const form = createBrowseForm();
        form.syncFromTab({
            id: 12,
            label: 'Local Tab',
            position: 0,
            isActive: true,
            params: {
                feed: 'local',
                source: 'all',
                page: 1,
                limit: 20,
            } as any,
        });

        const wrapper = mount(TabFilter, {
            props: {
                open: true,
                availableServices: [],
                localDef: {
                    key: 'local',
                    label: 'Local Files',
                    defaults: {},
                    schema: {
                        fields: [
                            { uiKey: 'page', serviceKey: 'page', type: 'hidden', label: 'Page' },
                            { uiKey: 'limit', serviceKey: 'limit', type: 'number', label: 'Limit' },
                            {
                                uiKey: 'source',
                                serviceKey: 'source',
                                type: 'select',
                                label: 'Source',
                                options: [{ label: 'All', value: 'all' }],
                            },
                        ],
                    },
                },
                masonry: null,
            },
            global: {
                provide: {
                    [BrowseFormKey as symbol]: form,
                },
                stubs: {
                    Sheet: Stub,
                    SheetContent: Stub,
                    SheetHeader: Stub,
                    SheetTitle: Stub,
                    SheetTrigger: Stub,
                    SheetFooter: Stub,
                    Select: Stub,
                    SelectContent: Stub,
                    SelectItem: Stub,
                    SelectTrigger: Stub,
                    SelectValue: Stub,
                    RadioGroup: Stub,
                    RadioGroupItem: Stub,
                    Switch: Stub,
                    Checkbox: Stub,
                    Button: Stub,
                },
            },
        });

        await nextTick();

        expect(wrapper.text()).toContain('Common');
        expect(wrapper.text()).toContain('Unreacted');
        expect(wrapper.text()).toContain('Unreacted (Random)');
        expect(wrapper.text()).toContain('Unreacted (Newest)');
        expect(wrapper.text()).toContain('Unreacted (Oldest)');
        expect(wrapper.text()).toContain('Favorite (Random)');
        expect(wrapper.text()).toContain('Favorite (Newest)');
        expect(wrapper.text()).toContain('Favorite (Oldest)');
        expect(wrapper.text()).toContain('Likes (Random)');
        expect(wrapper.text()).toContain('Likes (Newest)');
        expect(wrapper.text()).toContain('Likes (Oldest)');
        expect(wrapper.text()).toContain('Funny (Random)');
        expect(wrapper.text()).toContain('Funny (Newest)');
        expect(wrapper.text()).toContain('Funny (Oldest)');
        expect(wrapper.text()).toContain('Moderation');
        expect(wrapper.text()).toContain('Anomalies');
        expect(wrapper.text()).toContain('Blacklisted (Newest)');
        expect(wrapper.text()).toContain('Blacklisted (Oldest)');
        expect(wrapper.text()).toContain('Not Found');
        expect(wrapper.text()).toContain('Not Found (Reacted)');
        expect(wrapper.text()).toContain('Saved Blacklisted');
        expect(wrapper.text()).not.toContain('Auto blacklisted');
        expect(wrapper.text()).not.toContain('Saved Auto blacklisted');
    });
});
