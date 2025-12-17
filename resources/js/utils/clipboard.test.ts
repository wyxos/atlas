import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { copyToClipboard } from './clipboard';

// Mock toast
vi.mock('../components/ui/sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

describe('clipboard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        // Mock navigator.clipboard
        Object.assign(navigator, {
            clipboard: {
                writeText: vi.fn().mockResolvedValue(undefined),
            },
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('copies text to clipboard and shows success toast', async () => {
        const { toast } = await import('../components/ui/sonner');
        const text = 'test text';
        const label = 'URL';

        await copyToClipboard(text, label);

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith(text);
        expect(toast.success).toHaveBeenCalledWith(
            `${label} copied to clipboard`,
            { description: text }
        );
    });

    it('handles clipboard errors gracefully', async () => {
        const { toast } = await import('../components/ui/sonner');
        const error = new Error('Clipboard error');
        vi.mocked(navigator.clipboard.writeText).mockRejectedValue(error);

        await expect(copyToClipboard('test', 'Label')).rejects.toThrow();

        expect(toast.error).toHaveBeenCalledWith(
            'Failed to copy to clipboard',
            { description: 'Please try again or copy manually' }
        );
    });

    it('can disable toast notifications', async () => {
        const { toast } = await import('../components/ui/sonner');
        const text = 'test text';
        const label = 'URL';

        await copyToClipboard(text, label, { showToast: false });

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith(text);
        expect(toast.success).not.toHaveBeenCalled();
    });

    it('does not show error toast when showToast is false', async () => {
        const { toast } = await import('../components/ui/sonner');
        const error = new Error('Clipboard error');
        vi.mocked(navigator.clipboard.writeText).mockRejectedValue(error);

        await expect(
            copyToClipboard('test', 'Label', { showToast: false })
        ).rejects.toThrow();

        expect(toast.error).not.toHaveBeenCalled();
    });
});

