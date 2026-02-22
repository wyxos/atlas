// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { createDialogChooser, ensurePageMarkerStyles } from './ui';

describe('createDialogChooser', () => {
  it('resolves cancel when Escape is pressed', async () => {
    const root = document.createElement('div');
    document.body.appendChild(root);

    const chooseDialog = createDialogChooser(root);
    const promise = chooseDialog({
      title: 'Confirm action',
      message: 'Proceed?',
      confirmLabel: 'Confirm',
      cancelLabel: 'Cancel',
    });

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await expect(promise).resolves.toBe('cancel');
    expect(root.querySelector('.atlas-downloader-dialog-backdrop')).toBeNull();
  });
});

describe('ensurePageMarkerStyles', () => {
  it('injects page marker styles once', () => {
    ensurePageMarkerStyles();
    ensurePageMarkerStyles();
    expect(document.querySelectorAll('#atlas-downloader-page-markers')).toHaveLength(1);
  });
});
