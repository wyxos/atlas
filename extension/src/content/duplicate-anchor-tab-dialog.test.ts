import { beforeEach, describe, expect, it } from 'vitest';

import { createDuplicateAnchorTabDialog } from './duplicate-anchor-tab-dialog';

describe('duplicate-anchor-tab-dialog', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('reuses a single active dialog and updates the displayed url', () => {
        const dialog = createDuplicateAnchorTabDialog();

        dialog.show('https://example.com/post#image-1');
        dialog.show('https://example.com/post#image-2');

        const roots = document.querySelectorAll('[data-atlas-duplicate-tab-dialog="1"]');
        expect(roots).toHaveLength(1);
        expect(document.body.textContent).toContain('Link already open');
        expect(document.body.textContent).toContain('https://example.com/post#image-2');

        dialog.destroy();
    });

    it('dismisses on escape and backdrop click', () => {
        const dialog = createDuplicateAnchorTabDialog();

        dialog.show('https://example.com/post#image-1');
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(document.querySelector('[data-atlas-duplicate-tab-dialog="1"]')).toBeNull();

        dialog.show('https://example.com/post#image-1');
        const backdrop = document.querySelector('[data-atlas-duplicate-tab-dialog="1"]');
        expect(backdrop).toBeTruthy();

        backdrop?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(document.querySelector('[data-atlas-duplicate-tab-dialog="1"]')).toBeNull();

        dialog.destroy();
    });
});
