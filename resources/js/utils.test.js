import { describe, it, expect } from 'vitest';
import { cn } from '@/lib/utils';

describe('Utility Functions', () => {
    describe('cn (className utility)', () => {
        it('merges class names correctly', () => {
            expect(cn('foo', 'bar')).toBe('foo bar');
        });

        it('handles conditional classes', () => {
            expect(cn('foo', false && 'bar', 'baz')).toBe('foo baz');
            expect(cn('foo', true && 'bar')).toBe('foo bar');
        });

        it('merges Tailwind classes correctly', () => {
            // twMerge should deduplicate conflicting classes
            expect(cn('px-2 py-1', 'px-4')).toContain('px-4');
        });

        it('handles empty inputs', () => {
            expect(cn()).toBe('');
            expect(cn('', null, undefined)).toBe('');
        });

        it('handles arrays of classes', () => {
            expect(cn(['foo', 'bar'], 'baz')).toBe('foo bar baz');
        });
    });
});
