import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { formatDate } from './date';

describe('date', () => {
    beforeEach(() => {
        // Mock current date to 2024-01-15 15:45:00
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2024-01-15T15:45:00'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('formats today as time only', () => {
        const today = '2024-01-15T15:45:00';
        const result = formatDate(today);

        expect(result).toBe('15:45');
    });

    it('formats yesterday with "Yesterday" prefix', () => {
        const yesterday = '2024-01-14T15:45:00';
        const result = formatDate(yesterday);

        expect(result).toBe('Yesterday 15:45');
    });

    it('formats current month as weekday and day', () => {
        const currentMonth = '2024-01-10T15:45:00';
        const result = formatDate(currentMonth);

        // Should be "Wed, 10 15:45" or "10 Wed, 15:45" (format depends on locale)
        expect(result).toMatch(/15:45$/);
        expect(result).toMatch(/\d{1,2}/);
    });

    it('formats other dates with full format', () => {
        const otherDate = '2023-12-15T15:45:00';
        const result = formatDate(otherDate);

        // Should include year, month, day, and time
        expect(result).toMatch(/2023/);
        expect(result).toMatch(/15:45/);
    });

    it('handles different times correctly', () => {
        const morning = '2024-01-15T09:30:00';
        const result = formatDate(morning);

        expect(result).toBe('09:30');
    });

    it('handles midnight correctly', () => {
        const midnight = '2024-01-15T00:00:00';
        const result = formatDate(midnight);

        expect(result).toBe('00:00');
    });

    it('handles end of day correctly', () => {
        const endOfDay = '2024-01-15T23:59:00';
        const result = formatDate(endOfDay);

        expect(result).toBe('23:59');
    });
});

