import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, nextTick } from 'vue';
import TabFilter from './TabFilter.vue';
import { BrowseFormKey, createBrowseForm } from '@/composables/useBrowseForm';
import type { TabData } from '@/composables/useTabs';

const Stub = defineComponent({
    template: '<div><slot /></div>',
});

describe('TabFilter', () => {
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
                local_preset: 'reacted_random',
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

        expect(wrapper.text()).toContain('Reacted (Random)');
        expect(form.data.serviceFilters.local_preset).toBe('reacted_random');
    });

    it('includes disliked any preset option', async () => {
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

        expect(wrapper.text()).toContain('Disliked (Any)');
    });
});
