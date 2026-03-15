import { beforeEach, describe, expect, it } from 'vitest';

import { createDownloadedReactionDialog } from './downloaded-reaction-dialog';

describe('downloaded-reaction-dialog', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('reuses a single dialog and resolves the selected action', async () => {
        const dialog = createDownloadedReactionDialog();

        const firstPrompt = dialog.prompt();
        const secondPrompt = dialog.prompt();

        expect(firstPrompt).toBe(secondPrompt);
        expect(document.querySelectorAll('[data-atlas-downloaded-reaction-dialog="1"]')).toHaveLength(1);

        const reactOnlyButton = Array.from(document.querySelectorAll('button'))
            .find((button) => button.textContent === 'Update reaction');
        expect(reactOnlyButton).toBeTruthy();

        reactOnlyButton?.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        await expect(firstPrompt).resolves.toBe('react');

        dialog.destroy();
    });

    it('dismisses on escape and destroy by resolving cancel', async () => {
        const dialog = createDownloadedReactionDialog();

        const escapePrompt = dialog.prompt();
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await expect(escapePrompt).resolves.toBe('cancel');

        const destroyPrompt = dialog.prompt();
        dialog.destroy();
        await expect(destroyPrompt).resolves.toBe('cancel');
    });

    it('returns the redownload choice when the refresh action is selected', async () => {
        const dialog = createDownloadedReactionDialog();

        const prompt = dialog.prompt();
        const redownloadButton = Array.from(document.querySelectorAll('button'))
            .find((button) => button.textContent === 'Update and download again');

        redownloadButton?.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        await expect(prompt).resolves.toBe('redownload');

        dialog.destroy();
    });
});
