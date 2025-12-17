import { describe, it, expect } from 'vitest';
import {
    formatFileSize,
    getMimeTypeCategory,
    getMimeTypeBadgeClasses,
} from './file';

describe('file', () => {
    describe('formatFileSize', () => {
        it('formats bytes correctly', () => {
            expect(formatFileSize(0)).toBe('0 B');
            expect(formatFileSize(500)).toBe('500 B');
            expect(formatFileSize(1024)).toBe('1 KB');
            expect(formatFileSize(1536)).toBe('1.5 KB');
        });

        it('formats kilobytes correctly', () => {
            expect(formatFileSize(1024 * 5)).toBe('5 KB');
            expect(formatFileSize(1024 * 512)).toBe('512 KB');
        });

        it('formats megabytes correctly', () => {
            expect(formatFileSize(1024 * 1024)).toBe('1 MB');
            expect(formatFileSize(1024 * 1024 * 2.5)).toBe('2.5 MB');
        });

        it('formats gigabytes correctly', () => {
            expect(formatFileSize(1024 * 1024 * 1024)).toBe('1 GB');
            expect(formatFileSize(1024 * 1024 * 1024 * 2.5)).toBe('2.5 GB');
        });

        it('handles null', () => {
            expect(formatFileSize(null)).toBe('0 B');
        });
    });

    describe('getMimeTypeCategory', () => {
        it('categorizes image mime types', () => {
            expect(getMimeTypeCategory('image/jpeg')).toBe('image');
            expect(getMimeTypeCategory('image/png')).toBe('image');
            expect(getMimeTypeCategory('image/gif')).toBe('image');
        });

        it('categorizes video mime types', () => {
            expect(getMimeTypeCategory('video/mp4')).toBe('video');
            expect(getMimeTypeCategory('video/webm')).toBe('video');
        });

        it('categorizes audio mime types', () => {
            expect(getMimeTypeCategory('audio/mpeg')).toBe('audio');
            expect(getMimeTypeCategory('audio/ogg')).toBe('audio');
        });

        it('returns other for unknown types', () => {
            expect(getMimeTypeCategory('application/pdf')).toBe('other');
            expect(getMimeTypeCategory('text/plain')).toBe('other');
        });

        it('returns unknown for null', () => {
            expect(getMimeTypeCategory(null)).toBe('unknown');
        });
    });

    describe('getMimeTypeBadgeClasses', () => {
        it('returns correct classes for image', () => {
            const classes = getMimeTypeBadgeClasses('image/jpeg');
            expect(classes).toContain('bg-blue-500/20');
            expect(classes).toContain('text-blue-300');
        });

        it('returns correct classes for video', () => {
            const classes = getMimeTypeBadgeClasses('video/mp4');
            expect(classes).toContain('bg-purple-500/20');
            expect(classes).toContain('text-purple-300');
        });

        it('returns correct classes for audio', () => {
            const classes = getMimeTypeBadgeClasses('audio/mpeg');
            expect(classes).toContain('bg-green-500/20');
            expect(classes).toContain('text-green-300');
        });

        it('returns correct classes for other', () => {
            const classes = getMimeTypeBadgeClasses('application/pdf');
            expect(classes).toContain('bg-twilight-indigo-500/20');
            expect(classes).toContain('text-twilight-indigo-100');
        });

        it('returns correct classes for unknown', () => {
            const classes = getMimeTypeBadgeClasses(null);
            expect(classes).toContain('bg-twilight-indigo-500/20');
            expect(classes).toContain('text-twilight-indigo-700');
        });
    });
});

