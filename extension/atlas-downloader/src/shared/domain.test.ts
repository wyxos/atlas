import { describe, expect, it } from 'vitest';
import { registrableDomain, registrableDomainFromUrl } from './domain';

describe('registrableDomain', () => {
  it('strips subdomain for common cases', () => {
    expect(registrableDomain('w.wallhaven.cc')).toBe('wallhaven.cc');
    expect(registrableDomain('sub.example.com')).toBe('example.com');
  });

  it('handles multi-part TLDs', () => {
    expect(registrableDomain('a.b.example.co.uk')).toBe('example.co.uk');
  });

  it('handles empty input', () => {
    expect(registrableDomain('')).toBe('');
  });
});

describe('registrableDomainFromUrl', () => {
  it('parses URLs', () => {
    expect(registrableDomainFromUrl('https://w.wallhaven.cc/full/3q/wallhaven-3q3xr3.png')).toBe(
      'wallhaven.cc'
    );
  });
});

