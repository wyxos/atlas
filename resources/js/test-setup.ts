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

