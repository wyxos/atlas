import { beforeEach, vi } from 'vitest';

// Mock window.matchMedia for Oruga components (must be set up before imports)
if (typeof window !== 'undefined' && !window.matchMedia) {
    Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: vi.fn().mockImplementation((query) => ({
            matches: false,
            media: query,
            onchange: null,
            addListener: vi.fn(),
            removeListener: vi.fn(),
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            dispatchEvent: vi.fn(),
        })),
    });
}

// Mock IntersectionObserver for @wyxos/vibe and other components
if (typeof window !== 'undefined' && !window.IntersectionObserver) {
    class MockIntersectionObserver {
        root: Element | null = null;
        rootMargin: string = '';
        thresholds: ReadonlyArray<number> = [];
        callback: IntersectionObserverCallback;

        constructor(callback: IntersectionObserverCallback) {
            this.callback = callback;
        }
        observe = vi.fn();
        unobserve = vi.fn();
        disconnect = vi.fn();
        takeRecords = vi.fn(() => []);
    }
    Object.defineProperty(window, 'IntersectionObserver', {
        writable: true,
        value: MockIntersectionObserver,
    });
}

// Mock Element.scrollTo for @wyxos/vibe masonry component
if (typeof Element !== 'undefined' && !Element.prototype.scrollTo) {
    Element.prototype.scrollTo = vi.fn();
}

// Suppress Vue Router warnings in tests
beforeEach(() => {
    const originalWarn = console.warn;
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation((message, ...args) => {
        // Suppress Vue Router "No match found" warnings
        if (typeof message === 'string' && message.includes('No match found for location')) {
            return;
        }
        // Prevent infinite recursion by checking if this is our own warn call
        if (warnSpy.mock.calls.length > 100) {
            return;
        }
        originalWarn(message, ...args);
    });
});

