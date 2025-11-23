import { describe, it, expect } from 'vitest';

describe('Math Operations', () => {
    it('should calculate the area of a circle', () => {
        const circleArea = (radius) => Math.PI * radius * radius;
        
        expect(circleArea(1)).toBeCloseTo(Math.PI, 5);
        expect(circleArea(2)).toBeCloseTo(12.56637, 5);
        expect(circleArea(0)).toBe(0);
    });

    it('should find the maximum value in an array', () => {
        const findMax = (arr) => Math.max(...arr);

        expect(findMax([1, 5, 3, 9, 2])).toBe(9);
        expect(findMax([-10, -5, -20])).toBe(-5);
        expect(findMax([42])).toBe(42);
    });

    it('should calculate the factorial of a number', () => {
        const factorial = (n) => {
            if (n === 0 || n === 1) return 1;
            return n * factorial(n - 1);
        };

        expect(factorial(0)).toBe(1);
        expect(factorial(1)).toBe(1);
        expect(factorial(5)).toBe(120);
        expect(factorial(10)).toBe(3628800);
    });

    it('should check if a number is prime', () => {
        const isPrime = (n) => {
            if (n < 2) return false;
            for (let i = 2; i <= Math.sqrt(n); i++) {
                if (n % i === 0) return false;
            }
            return true;
        };

        expect(isPrime(2)).toBe(true);
        expect(isPrime(3)).toBe(true);
        expect(isPrime(4)).toBe(false);
        expect(isPrime(17)).toBe(true);
        expect(isPrime(20)).toBe(false);
        expect(isPrime(1)).toBe(false);
        expect(isPrime(0)).toBe(false);
    });
});


