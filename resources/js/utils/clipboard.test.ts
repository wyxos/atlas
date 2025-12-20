import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { copyToClipboard } from './clipboard';


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

    it('copies text to clipboard', async () => {
        const text = 'test text';
        const label = 'URL';

        await copyToClipboard(text, label);

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith(text);
    });

    it('handles clipboard errors gracefully', async () => {
        const error = new Error('Clipboard error');
        vi.mocked(navigator.clipboard.writeText).mockRejectedValue(error);

        await expect(copyToClipboard('test', 'Label')).rejects.toThrow();
    });

    it('can disable toast notifications', async () => {
        const text = 'test text';
        const label = 'URL';

        await copyToClipboard(text, label, { showToast: false });

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith(text);
    });

    it('does not show error toast when showToast is false', async () => {
        const error = new Error('Clipboard error');
        vi.mocked(navigator.clipboard.writeText).mockRejectedValue(error);

        await expect(
            copyToClipboard('test', 'Label', { showToast: false })
        ).rejects.toThrow();
    });
});

