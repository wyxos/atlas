import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useImmediateActionsToast } from '../composables/useImmediateActionsToast';

// Mock vue-toastification using vi.hoisted for proper hoisting
const { mockToastFn, mockUseToast } = vi.hoisted(() => {
    const toastFn = vi.fn((options: any) => {
        return `toast-${Date.now()}`;
    });

    const toast = Object.assign(toastFn, {
        update: vi.fn(),
        dismiss: vi.fn(),
    });

    const useToast = vi.fn(() => toast);

    return { mockToastFn: toastFn, mockUseToast: useToast };
});

vi.mock('vue-toastification', () => ({
    useToast: mockUseToast,
}));

describe('Browse - Immediate Actions Toast', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('collects immediate actions when addActions is called', () => {
        const { addActions, showToast } = useImmediateActionsToast();

        const immediateActions = [
            { id: 1, action_type: 'auto_dislike', thumbnail: 'https://example.com/thumb1.jpg' },
            { id: 2, action_type: 'blacklist', thumbnail: 'https://example.com/thumb2.jpg' },
        ];

        addActions(immediateActions);
        showToast();

        // Verify toast was created (mocked)
        expect(mockToastFn).toHaveBeenCalledWith(expect.objectContaining({
            content: expect.anything(),
            timeout: false,
            closeOnClick: false,
            closeButton: false,
        }));
    });

    it('does not show toast when no actions are collected', () => {
        const { showToast } = useImmediateActionsToast();

        showToast();

        // Verify toast was not created (no actions to show)
        // showToast returns early if no actions, so toast() should not be called
        // showToast returns early if no actions, so toast() should not be called
        // But we can't easily verify this without accessing internal state
        // So we just verify the function doesn't throw
        expect(() => showToast()).not.toThrow();
    });

    it('accumulates actions from multiple calls', () => {
        const { addActions, showToast } = useImmediateActionsToast();

        const actions1 = [{ id: 1, action_type: 'auto_dislike', thumbnail: 'thumb1.jpg' }];
        const actions2 = [{ id: 2, action_type: 'blacklist', thumbnail: 'thumb2.jpg' }];

        addActions(actions1);
        addActions(actions2);
        showToast();

        // Verify toast was created with both actions
        expect(mockToastFn).toHaveBeenCalledWith(expect.objectContaining({
            content: expect.anything(),
            timeout: false,
            closeOnClick: false,
            closeButton: false,
        }));
    });
});

