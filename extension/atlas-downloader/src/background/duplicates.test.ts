import { describe, expect, it } from 'vitest';
import {
  findDuplicateTabId,
  normalizeTabUrlForDuplicateCheck,
  pickDuplicateNoticeTargetTabId,
} from './duplicates';

describe('normalizeTabUrlForDuplicateCheck', () => {
  it('keeps http and https urls while stripping hash', () => {
    expect(normalizeTabUrlForDuplicateCheck('https://Example.com/path?q=1#section')).toBe(
      'https://example.com/path?q=1'
    );
    expect(normalizeTabUrlForDuplicateCheck('http://example.com/path#x')).toBe('http://example.com/path');
  });

  it('ignores non-web protocols and invalid urls', () => {
    expect(normalizeTabUrlForDuplicateCheck('chrome://extensions')).toBe('');
    expect(normalizeTabUrlForDuplicateCheck('about:blank')).toBe('');
    expect(normalizeTabUrlForDuplicateCheck('not-a-url')).toBe('');
  });
});

describe('findDuplicateTabId', () => {
  it('returns matching tab id for duplicate page urls', () => {
    const tabs = [
      { id: 10, url: 'https://example.com/page?a=1' },
      { id: 11, url: 'https://example.com/other' },
      { id: 12, url: 'https://example.com/page?a=1#fragment' },
    ];

    expect(findDuplicateTabId(tabs, 12, 'https://example.com/page?a=1')).toBe(10);
  });

  it('returns null when no duplicate tab exists', () => {
    const tabs = [
      { id: 20, url: 'https://example.com/alpha' },
      { id: 21, url: 'https://example.com/beta' },
    ];

    expect(findDuplicateTabId(tabs, 21, 'https://example.com/gamma')).toBeNull();
  });
});

describe('pickDuplicateNoticeTargetTabId', () => {
  it('prefers opener tab when available', () => {
    const tabs = [
      { id: 100, active: true },
      { id: 101, active: false },
      { id: 102, active: false },
    ];

    expect(pickDuplicateNoticeTargetTabId(tabs, 102, 101, 100)).toBe(100);
  });

  it('falls back to active tab when opener is unavailable', () => {
    const tabs = [
      { id: 200, active: false },
      { id: 201, active: true },
      { id: 202, active: false },
    ];

    expect(pickDuplicateNoticeTargetTabId(tabs, 202, 200, null)).toBe(201);
  });

  it('returns null when no suitable tab exists', () => {
    const tabs = [
      { id: 301, active: false },
      { id: 302, active: false },
    ];

    expect(pickDuplicateNoticeTargetTabId(tabs, 302, 301, null)).toBeNull();
  });
});
