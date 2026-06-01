import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import TabFilter from './TabFilter.vue';
import { BrowseFormKey, createBrowseForm } from '@/composables/useBrowseForm';
import type { TabData } from '@/composables/useTabs';

const Stub = defineComponent({
    template: '<div><slot /></div>',
});

const SearchableDropdownStub = defineComponent({
    name: 'SearchableDropdownStub',
    props: {
        modelValue: { default: '' },
        options: { type: Array, default: () => [] },
        groups: { type: Array, default: () => [] },
        placeholder: { type: String, default: '' },
    },
    setup(props) {
        return () => h('div', [
            ...((props.options as Array<{ label?: string }>).map((option) => option.label ?? '')),
            ...((props.groups as Array<{ label?: string; options?: Array<{ label?: string }> }>).flatMap((group) => [
                group.label ?? '',
                ...(group.options ?? []).map((option) => option.label ?? ''),
            ])),
            props.placeholder,
        ].filter(Boolean).join(' '));
    },
});

const DatePickerStub = defineComponent({
    name: 'DatePicker',
    props: {
        modelValue: { type: String, default: '' },
        placeholder: { type: String, default: '' },
    },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        return () => h('div', [
            h('input', {
                value: props.modelValue,
                placeholder: props.placeholder,
                onInput: (event: Event) => {
                    emit('update:modelValue', (event.target as HTMLInputElement).value);
                },
            }),
            props.modelValue
                ? h('button', {
                    type: 'button',
                    'aria-label': `Clear ${props.placeholder}`,
                    onClick: () => emit('update:modelValue', ''),
                }, 'Clear')
                : null,
        ]);
    },
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
                    LocalSourceDropdown: Stub,
                    Select: Stub,
                    SelectContent: Stub,
                    SelectItem: Stub,
                    SelectTrigger: Stub,
                    SelectValue: Stub,
                    SearchableDropdown: SearchableDropdownStub,
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
                    label: 'Library',
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
                    LocalSourceDropdown: Stub,
                    Select: Stub,
                    SelectContent: Stub,
                    SelectItem: Stub,
                    SelectTrigger: Stub,
                    SelectValue: Stub,
                    SearchableDropdown: SearchableDropdownStub,
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

    it('applies local date range fields as service filters', async () => {
        const form = createBrowseForm();
        form.syncFromTab({
            id: 13,
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
                    label: 'Library',
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
                            {
                                uiKey: 'date_from',
                                serviceKey: 'date_from',
                                type: 'date',
                                label: 'Created From',
                                placeholder: 'From date',
                            },
                            {
                                uiKey: 'date_to',
                                serviceKey: 'date_to',
                                type: 'date',
                                label: 'Created To',
                                placeholder: 'To date',
                            },
                            {
                                uiKey: 'downloaded_at_from',
                                serviceKey: 'downloaded_at_from',
                                type: 'date',
                                label: 'Downloaded From',
                                placeholder: 'From date',
                            },
                            {
                                uiKey: 'downloaded_at_to',
                                serviceKey: 'downloaded_at_to',
                                type: 'date',
                                label: 'Downloaded To',
                                placeholder: 'To date',
                            },
                            {
                                uiKey: 'blacklisted_at_from',
                                serviceKey: 'blacklisted_at_from',
                                type: 'date',
                                label: 'Blacklisted From',
                                placeholder: 'From date',
                            },
                            {
                                uiKey: 'blacklisted_at_to',
                                serviceKey: 'blacklisted_at_to',
                                type: 'date',
                                label: 'Blacklisted To',
                                placeholder: 'To date',
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
                    LocalSourceDropdown: Stub,
                    Select: Stub,
                    SelectContent: Stub,
                    SelectItem: Stub,
                    SelectTrigger: Stub,
                    SelectValue: Stub,
                    SearchableDropdown: SearchableDropdownStub,
                    DatePicker: DatePickerStub,
                    RadioGroup: Stub,
                    RadioGroupItem: Stub,
                    Switch: Stub,
                    Checkbox: Stub,
                    Button: Stub,
                },
            },
        });

        const fromInputs = wrapper.findAll('input[placeholder="From date"]');
        const toInputs = wrapper.findAll('input[placeholder="To date"]');

        expect(fromInputs).toHaveLength(3);
        expect(toInputs).toHaveLength(3);

        await fromInputs[0]!.setValue('2026-05-01');
        await toInputs[0]!.setValue('2026-05-30');
        await fromInputs[1]!.setValue('2026-04-01');
        await toInputs[1]!.setValue('2026-04-30');
        await fromInputs[2]!.setValue('2026-03-01');
        await toInputs[2]!.setValue('2026-03-31');

        expect(form.data.serviceFilters.date_from).toBe('2026-05-01');
        expect(form.data.serviceFilters.date_to).toBe('2026-05-30');
        expect(form.data.serviceFilters.downloaded_at_from).toBe('2026-04-01');
        expect(form.data.serviceFilters.downloaded_at_to).toBe('2026-04-30');
        expect(form.data.serviceFilters.blacklisted_at_from).toBe('2026-03-01');
        expect(form.data.serviceFilters.blacklisted_at_to).toBe('2026-03-31');

        while (wrapper.findAll('button[aria-label="Clear From date"]').length > 0) {
            await wrapper.findAll('button[aria-label="Clear From date"]')[0]!.trigger('click');
        }
        while (wrapper.findAll('button[aria-label="Clear To date"]').length > 0) {
            await wrapper.findAll('button[aria-label="Clear To date"]')[0]!.trigger('click');
        }

        expect(form.data.serviceFilters.date_from).toBe('');
        expect(form.data.serviceFilters.date_to).toBe('');
        expect(form.data.serviceFilters.downloaded_at_from).toBe('');
        expect(form.data.serviceFilters.downloaded_at_to).toBe('');
        expect(form.data.serviceFilters.blacklisted_at_from).toBe('');
        expect(form.data.serviceFilters.blacklisted_at_to).toBe('');
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
                    label: 'Library',
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
                    LocalSourceDropdown: Stub,
                    Select: Stub,
                    SelectContent: Stub,
                    SelectItem: Stub,
                    SelectTrigger: Stub,
                    SelectValue: Stub,
                    SearchableDropdown: SearchableDropdownStub,
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
        expect(wrapper.text()).toContain('Imported Files');
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
        expect(wrapper.text()).toContain('Out of Feed (Newest)');
        expect(wrapper.text()).toContain('Out of Feed (Oldest)');
        expect(wrapper.text()).toContain('Not Found');
        expect(wrapper.text()).toContain('Not Found (Reacted)');
        expect(wrapper.text()).toContain('Saved Blacklisted');
        expect(wrapper.text()).not.toContain('Auto blacklisted');
        expect(wrapper.text()).not.toContain('Saved Auto blacklisted');
    });
});
