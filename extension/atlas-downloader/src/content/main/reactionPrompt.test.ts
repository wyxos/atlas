import { describe, expect, it } from 'vitest';
import { resolveSheetReactionPrompt } from './reactionPrompt';

describe('resolveSheetReactionPrompt', () => {
  it('returns re-download prompt for downloaded non-dislike reactions', () => {
    const prompt = resolveSheetReactionPrompt('like', true, false);
    expect(prompt).toEqual({
      kind: 're-download',
      title: 'Already downloaded',
      message: 'This file is already downloaded. Re-download before updating the reaction?',
      confirmLabel: 'Re-download',
      cancelLabel: 'Keep existing file',
      alternateLabel: 'Cancel',
    });
  });

  it('returns clear-download prompt for downloaded dislike reactions', () => {
    const prompt = resolveSheetReactionPrompt('dislike', true, false);
    expect(prompt).toEqual({
      kind: 'clear-download',
      title: 'Dislike file',
      message: 'Delete the downloaded file before applying this action?',
      confirmLabel: 'Delete then proceed',
      cancelLabel: 'Keep file and proceed',
      alternateLabel: 'Cancel',
      danger: true,
    });
  });

  it('uses blacklist title for downloaded blacklist reactions', () => {
    const prompt = resolveSheetReactionPrompt('dislike', true, true);
    expect(prompt?.kind).toBe('clear-download');
    expect(prompt?.title).toBe('Blacklist file');
  });

  it('returns null when item is not downloaded', () => {
    expect(resolveSheetReactionPrompt('like', false, false)).toBeNull();
    expect(resolveSheetReactionPrompt('dislike', false, true)).toBeNull();
  });
});
