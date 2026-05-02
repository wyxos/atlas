import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import TabContentStartForm from './TabContentStartForm.vue';
import { BrowseFormKey, createBrowseForm, type BrowseFormInstance } from '@/composables/useBrowseForm';

const simpleStub = defineComponent({
    name: 'SimpleStub',
    setup(_props, { slots }) {
        return () => h('div', slots.default?.());
    },
});

const buttonStub = defineComponent({
    name: 'ButtonStub',
    props: {
        disabled: { type: Boolean, default: false },
    },
    setup(props, { attrs, slots }) {
        return () => h('button', { ...attrs, disabled: props.disabled }, slots.default?.());
    },
});

const fieldStub = defineComponent({
    name: 'TabFilterFieldControlStub',
    props: {
        field: { type: Object, required: true },
    },
    setup(props) {
        return () => h('div', { 'data-test': 'start-filter-field' }, (props.field as { label?: string }).label);
    },
});

const limitStub = defineComponent({
    name: 'TabFilterLimitFieldStub',
    setup() {
        return () => h('div', { 'data-test': 'start-limit-field' }, 'Limit');
    },
});

function createForm(overrides: Record<string, unknown> = {}): BrowseFormInstance {
    const form = createBrowseForm();

    Object.assign(form.data, overrides);

    return form;
}

function mountStartForm(
    props: Partial<InstanceType<typeof TabContentStartForm>['$props']> = {},
    form: BrowseFormInstance = createForm(),
) {
    const updateService = props.updateService ?? vi.fn((service: string) => {
        form.setService(service);
    });

    return mount(TabContentStartForm, {
        props: {
            availableServices: [
                {
                    key: 'civit-ai-images',
                    label: 'CivitAI Images',
                    schema: {
                        fields: [
                            { uiKey: 'limit', serviceKey: 'civit-ai-images', type: 'number', label: 'Limit' },
                            {
                                uiKey: 'sort',
                                serviceKey: 'civit-ai-images',
                                type: 'select',
                                label: 'Sort',
                                options: [{ label: 'Newest', value: 'Newest' }],
                            },
                        ],
                    },
                },
                { key: 'wallhaven', label: 'Wallhaven' },
            ],
            isLoading: false,
            localService: null,
            setLocalMode: vi.fn(),
            updateService,
            updateSource: vi.fn(),
            applyService: vi.fn(),
            ...props,
        },
        global: {
            provide: {
                [BrowseFormKey as symbol]: form,
            },
            stubs: {
                Button: buttonStub,
                Input: simpleStub,
                Play: simpleStub,
                Select: simpleStub,
                SelectContent: simpleStub,
                SelectItem: simpleStub,
                SelectTrigger: simpleStub,
                SelectValue: simpleStub,
                Switch: simpleStub,
                TabFilterFieldControl: fieldStub,
                TabFilterLimitField: limitStub,
            },
        },
    });
}

describe('TabContentStartForm', () => {
    it('auto-selects the first online service for a new browse tab', async () => {
        const form = createForm();
        const updateService = vi.fn((service: string) => {
            form.data.service = service;
        });

        mountStartForm({ updateService }, form);
        await nextTick();

        expect(updateService).toHaveBeenCalledWith('civit-ai-images');
        expect(form.data.service).toBe('civit-ai-images');
    });

    it('renders the selected service filter fields in the setup sheet before browsing starts', () => {
        const form = createForm({ service: 'civit-ai-images' });
        const wrapper = mountStartForm({}, form);

        expect(wrapper.get('[data-test="new-tab-setup-sheet"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="start-limit-field"]').text()).toContain('Limit');
        expect(wrapper.get('[data-test="start-filter-field"]').text()).toContain('Sort');
    });
});
