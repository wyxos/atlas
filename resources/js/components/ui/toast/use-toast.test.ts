import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from './use-toast';

const { sonnerToast } = vi.hoisted(() => {
    const mockToast = Object.assign(vi.fn(), {
        success: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
        custom: vi.fn(),
        dismiss: vi.fn(),
    });

    return {
        sonnerToast: mockToast,
    };
});

vi.mock('vue-sonner', () => ({
    toast: sonnerToast,
}));

describe('toast', () => {
    beforeEach(() => {
        vi.useRealTimers();
        vi.clearAllMocks();
        sonnerToast.error.mockReturnValue('toast-id');
    });

    it('keeps general error toasts persistent by default', () => {
        toast.error('Failed to save reaction');

        expect(sonnerToast.error).toHaveBeenCalledWith('Failed to save reaction', expect.objectContaining({
            duration: Infinity,
        }));
    });

    it('auto-hides local browse unavailable toasts from any caller', () => {
        vi.useFakeTimers();

        toast.error('Local browse unavailable');

        expect(sonnerToast.error).toHaveBeenCalledWith('Local browse unavailable', expect.objectContaining({
            duration: 5000,
            id: 'local-browse-unavailable',
        }));
        expect(sonnerToast.dismiss).not.toHaveBeenCalled();

        vi.advanceTimersByTime(5000);

        expect(sonnerToast.dismiss).toHaveBeenCalledWith('toast-id');
        expect(sonnerToast.dismiss).toHaveBeenCalledWith();
    });
});
