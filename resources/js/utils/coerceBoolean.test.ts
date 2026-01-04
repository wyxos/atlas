import { describe, expect, it } from 'vitest';
import { coerceBoolean } from './coerceBoolean';

describe('coerceBoolean', () => {
    it('treats nullish values as false', () => {
        expect(coerceBoolean(null)).toBe(false);
        expect(coerceBoolean(undefined)).toBe(false);
    });

    it('treats booleans as-is', () => {
        expect(coerceBoolean(true)).toBe(true);
        expect(coerceBoolean(false)).toBe(false);
    });

    it('treats 0/1 numbers as false/true', () => {
        expect(coerceBoolean(0)).toBe(false);
        expect(coerceBoolean(1)).toBe(true);
    });

    it("treats '0' and 'false' strings as false", () => {
        expect(coerceBoolean('0')).toBe(false);
        expect(coerceBoolean('false')).toBe(false);
        expect(coerceBoolean('off')).toBe(false);
        expect(coerceBoolean('no')).toBe(false);
        expect(coerceBoolean('')).toBe(false);
        expect(coerceBoolean(' 0 ')).toBe(false);
    });

    it("treats '1' and 'true' strings as true", () => {
        expect(coerceBoolean('1')).toBe(true);
        expect(coerceBoolean('true')).toBe(true);
        expect(coerceBoolean('on')).toBe(true);
        expect(coerceBoolean('yes')).toBe(true);
        expect(coerceBoolean(' 1 ')).toBe(true);
    });
});
