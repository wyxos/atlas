import { beforeEach, vi } from 'vitest';

// Suppress Vue Router warnings in tests
beforeEach(() => {
    const originalWarn = console.warn;
    vi.spyOn(console, 'warn').mockImplementation((message, ...args) => {
        // Suppress Vue Router "No match found" warnings
        if (typeof message === 'string' && message.includes('No match found for location')) {
            return;
        }
        originalWarn(message, ...args);
    });
});

