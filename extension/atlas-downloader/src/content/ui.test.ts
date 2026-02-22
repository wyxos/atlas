// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest';
import {
  createDialogChooser,
  ensurePageMarkerStyles,
  showDuplicateTabBlockedModal,
} from './ui';

beforeEach(() => {
  document.body.innerHTML = '';
});

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

describe('showDuplicateTabBlockedModal', () => {
  it('renders modal and closes on Escape', () => {
    showDuplicateTabBlockedModal('https://example.com/path?q=1');

    const modal = document.getElementById('atlas-downloader-duplicate-modal');
    expect(modal).not.toBeNull();
    expect(modal?.textContent).toContain('This page is already open in another tab.');
    expect(modal?.textContent).toContain('https://example.com/path?q=1');

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(document.getElementById('atlas-downloader-duplicate-modal')).toBeNull();
  });

  it('keeps a single modal instance and omits empty url text', () => {
    showDuplicateTabBlockedModal('https://example.com/one');
    showDuplicateTabBlockedModal('');

    expect(document.querySelectorAll('#atlas-downloader-duplicate-modal')).toHaveLength(1);
    expect(document.querySelector('.atlas-downloader-duplicate-url')).toBeNull();
    expect(document.querySelectorAll('#atlas-downloader-duplicate-modal-style')).toHaveLength(1);

    const ok = document.querySelector<HTMLButtonElement>('.atlas-downloader-duplicate-btn');
    ok?.click();
    expect(document.getElementById('atlas-downloader-duplicate-modal')).toBeNull();
  });
});
