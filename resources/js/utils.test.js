import { describe, it, expect } from 'vitest';

describe('Utility Functions', () => {
    it('should add two numbers correctly', () => {
        const add = (a, b) => a + b;
        expect(add(2, 3)).toBe(5);
        expect(add(-1, 1)).toBe(0);
        expect(add(0, 0)).toBe(0);
    });

    it('should format a string correctly', () => {
        const formatName = (firstName, lastName) => `${firstName} ${lastName}`;
        expect(formatName('John', 'Doe')).toBe('John Doe');
        expect(formatName('Jane', 'Smith')).toBe('Jane Smith');
    });

    it('should validate email format', () => {
        const isValidEmail = (email) => {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        };

        expect(isValidEmail('test@example.com')).toBe(true);
        expect(isValidEmail('user.name@domain.co.uk')).toBe(true);
        expect(isValidEmail('invalid-email')).toBe(false);
        expect(isValidEmail('@example.com')).toBe(false);
        expect(isValidEmail('test@')).toBe(false);
    });

    it('should calculate the sum of an array', () => {
        const sumArray = (arr) => arr.reduce((sum, num) => sum + num, 0);

        expect(sumArray([1, 2, 3, 4, 5])).toBe(15);
        expect(sumArray([10, 20, 30])).toBe(60);
        expect(sumArray([])).toBe(0);
        expect(sumArray([-5, 5])).toBe(0);
    });
});


