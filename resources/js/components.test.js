import { describe, it, expect, beforeEach, vi } from 'vitest';

describe('Component Helpers', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('should create a DOM element', () => {
        const createElement = (tag, className, text) => {
            const element = document.createElement(tag);
            if (className) element.className = className;
            if (text) element.textContent = text;
            return element;
        };

        const div = createElement('div', 'test-class', 'Hello World');
        expect(div.tagName).toBe('DIV');
        expect(div.className).toBe('test-class');
        expect(div.textContent).toBe('Hello World');
    });

    it('should toggle a class on an element', () => {
        const toggleClass = (element, className) => {
            element.classList.toggle(className);
            return element.classList.contains(className);
        };

        const div = document.createElement('div');
        expect(toggleClass(div, 'active')).toBe(true);
        expect(toggleClass(div, 'active')).toBe(false);
        expect(toggleClass(div, 'active')).toBe(true);
    });

    it('should handle click events', () => {
        const handleClick = vi.fn();
        const button = document.createElement('button');
        button.addEventListener('click', handleClick);
        button.click();

        expect(handleClick).toHaveBeenCalledTimes(1);
    });

    it('should debounce function calls', () => {
        const debounce = (func, wait) => {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        };

        const mockFn = vi.fn();
        const debouncedFn = debounce(mockFn, 100);

        debouncedFn();
        debouncedFn();
        debouncedFn();

        expect(mockFn).not.toHaveBeenCalled();

        return new Promise((resolve) => {
            setTimeout(() => {
                expect(mockFn).toHaveBeenCalledTimes(1);
                resolve();
            }, 150);
        });
    });
});


