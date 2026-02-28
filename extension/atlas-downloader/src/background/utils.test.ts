// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { networkErrorMessage, normalizeBaseUrl, resolveMessageType } from './utils';

describe('background utils', () => {
  it('resolves message type when present', () => {
    expect(resolveMessageType({ type: 'atlas-open-sheet' })).toBe('atlas-open-sheet');
    expect(resolveMessageType({})).toBeNull();
    expect(resolveMessageType(null)).toBeNull();
  });

  it('normalizes base url', () => {
    expect(normalizeBaseUrl('atlas.test/')).toBe('https://atlas.test');
    expect(normalizeBaseUrl('http://atlas.test///')).toBe('http://atlas.test');
  });

  it('formats network errors', () => {
    expect(networkErrorMessage(new DOMException('Aborted', 'AbortError'))).toContain('timed out');
    expect(networkErrorMessage(new Error('boom'))).toContain('boom');
  });
});
