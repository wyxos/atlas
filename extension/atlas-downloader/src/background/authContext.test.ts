import { describe, expect, it, vi } from 'vitest';
import { attachAuthContextToPayload, type BrowserCookie } from './authContext';

describe('attachAuthContextToPayload', () => {
  it('adds auth_context with deduped cookies and host aliases for yt-dlp payloads', async () => {
    const getCookies = vi.fn(async (url: string): Promise<BrowserCookie[]> => {
      if (url.includes('x.com')) {
        return [
          {
            domain: '.x.com',
            path: '/',
            name: 'auth_token',
            value: 'token-x',
            secure: true,
            hostOnly: false,
            expirationDate: 1_900_000_000,
          },
        ];
      }

      if (url.includes('twitter.com')) {
        return [
          {
            domain: '.x.com',
            path: '/',
            name: 'auth_token',
            value: 'token-x-duplicate',
            secure: true,
            hostOnly: false,
            expirationDate: 1_900_000_000,
          },
          {
            domain: '.twitter.com',
            path: '/',
            name: 'ct0',
            value: 'csrf-token',
            secure: true,
            hostOnly: false,
            expirationDate: 1_900_000_123,
          },
        ];
      }

      return [];
    });

    const payload = await attachAuthContextToPayload(
      {
        url: 'https://x.com/devops_nk/status/2027073988082741620',
        referrer_url: 'https://x.com/devops_nk/status/2027073988082741620',
        tag_name: 'video',
        download_via: 'yt-dlp',
      },
      {
        getCookies,
        userAgent: 'AtlasTestAgent/1.0',
      },
    );

    const withAuth = payload as {
      auth_context?: {
        source_url: string;
        user_agent: string;
        cookies: Array<{ name: string; domain: string; path: string }>;
      };
    };

    expect(withAuth.auth_context?.source_url).toBe('https://x.com/devops_nk/status/2027073988082741620');
    expect(withAuth.auth_context?.user_agent).toBe('AtlasTestAgent/1.0');
    expect(withAuth.auth_context?.cookies).toHaveLength(2);
    expect(withAuth.auth_context?.cookies.map((cookie) => `${cookie.domain}:${cookie.name}`)).toEqual([
      '.x.com:auth_token',
      '.twitter.com:ct0',
    ]);

    // Main URL + aliases are queried.
    expect(getCookies).toHaveBeenCalledWith('https://x.com/devops_nk/status/2027073988082741620');
    expect(getCookies).toHaveBeenCalledWith('https://twitter.com/devops_nk/status/2027073988082741620');
  });

  it('does not add auth_context for non-video payloads', async () => {
    const payload = await attachAuthContextToPayload(
      {
        url: 'https://example.com/image.jpg',
        tag_name: 'img',
      },
      {
        getCookies: vi.fn(async () => []),
        userAgent: 'AtlasTestAgent/1.0',
      },
    );

    expect(payload).toEqual({
      url: 'https://example.com/image.jpg',
      tag_name: 'img',
    });
  });
});
