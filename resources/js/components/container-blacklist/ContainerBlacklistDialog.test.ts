import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ContainerBlacklistDialog from './ContainerBlacklistDialog.vue';

const checkBlacklist = vi.fn();
const deleteBlacklist = vi.fn();

vi.mock('@/composables/useContainerBlacklists', () => ({
    useContainerBlacklists: () => ({
        checkBlacklist,
        deleteBlacklist,
    }),
}));

const Stub = {
    template: '<div><slot /></div>',
};

const ButtonStub = {
    props: ['disabled'],
    emits: ['click'],
    template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
};

const SelectStub = {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template: '<div><slot /></div>',
};

describe('ContainerBlacklistDialog', () => {
    beforeEach(() => {
        checkBlacklist.mockReset();
        deleteBlacklist.mockReset();
    });

    it('renders the container signal counts with indicative styling', async () => {
        checkBlacklist.mockResolvedValue({
            blacklisted: true,
            blacklisted_at: '2024-01-15T10:00:00Z',
            action_type: 'dislike',
            file_stats: {
                unreacted: 12,
                blacklisted: 8,
                positive: 3,
            },
        });

        const wrapper = mount(ContainerBlacklistDialog, {
            props: {
                open: true,
                container: {
                    id: 42,
                    type: 'User',
                    source: 'CivitAI',
                    source_id: '123',
                    referrer: 'https://example.com/user/123',
                },
            },
            global: {
                stubs: {
                    Dialog: Stub,
                    DialogContent: Stub,
                    DialogDescription: Stub,
                    DialogFooter: Stub,
                    DialogHeader: Stub,
                    DialogTitle: Stub,
                    Button: ButtonStub,
                    Select: SelectStub,
                    SelectContent: Stub,
                    SelectItem: Stub,
                    SelectTrigger: Stub,
                    SelectValue: Stub,
                },
            },
        });

        await (wrapper.vm as { initializeState: () => Promise<void> }).initializeState();

        expect(checkBlacklist).toHaveBeenCalledWith(42);
        expect(wrapper.text()).toContain('Container Signal');
        expect(wrapper.get('[data-test="container-stat-unreacted"]').text()).toContain('12');
        expect(wrapper.get('[data-test="container-stat-blacklisted"]').text()).toContain('8');
        expect(wrapper.get('[data-test="container-stat-positive"]').text()).toContain('3');
        expect(wrapper.get('[data-test="container-stat-blacklisted"]').classes()).toContain('border-danger-500/40');
        expect(wrapper.get('[data-test="container-stat-positive"]').classes()).toContain('border-emerald-500/40');
    });
});
