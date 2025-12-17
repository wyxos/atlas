import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { openUrl } from './url';

describe('url', () => {
    let originalOpen: typeof window.open;

    beforeEach(() => {
        originalOpen = window.open;
        window.open = vi.fn();
    });

    afterEach(() => {
        window.open = originalOpen;
    });

    it('opens URL in new tab by default', () => {
        const url = 'https://example.com';

        openUrl(url);

        expect(window.open).toHaveBeenCalledWith(
            url,
            '_blank',
            'noopener,noreferrer'
        );
    });

    it('opens URL with custom target', () => {
        const url = 'https://example.com';

        openUrl(url, '_self');

        expect(window.open).toHaveBeenCalledWith(
            url,
            '_self',
            'noopener,noreferrer'
        );
    });

    it('always includes noopener and noreferrer', () => {
        const url = 'https://example.com';

        openUrl(url, '_blank');

        expect(window.open).toHaveBeenCalledWith(
            url,
            '_blank',
            'noopener,noreferrer'
        );
    });
});

