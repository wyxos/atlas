import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import HomeScreenshotCarousel from './HomeScreenshotCarousel.vue';
import type { HomeScreenshotSlide } from '@/types/homeScreenshot';

vi.mock('@/components/ui/carousel', () => ({
    Carousel: {
        name: 'Carousel',
        props: ['opts'],
        template: '<section v-bind="$attrs" data-test="mock-carousel"><slot /></section>',
    },
    CarouselContent: {
        name: 'CarouselContent',
        template: '<div data-test="mock-carousel-content"><slot /></div>',
    },
    CarouselItem: {
        name: 'CarouselItem',
        template: '<div data-test="mock-carousel-item"><slot /></div>',
    },
    CarouselNext: {
        name: 'CarouselNext',
        template: '<button type="button" data-test="mock-carousel-next">Next</button>',
    },
    CarouselPrevious: {
        name: 'CarouselPrevious',
        template: '<button type="button" data-test="mock-carousel-previous">Previous</button>',
    },
}));

const slides: HomeScreenshotSlide[] = [
    {
        alt: 'Browse grid sorted by most reactions',
        label: 'Feed sorting',
        src: '/home/browse-civitai-most-reactions.png',
    },
    {
        alt: 'Browse grid for an external image source',
        label: 'Source browsing',
        src: '/home/browse-deviantart.png',
    },
    {
        alt: 'Fullscreen image review mode',
        label: 'Full view',
        src: '/home/browse-full-view.png',
    },
];

describe('HomeScreenshotCarousel', () => {
    it('renders the provided screenshots as carousel slides', () => {
        const wrapper = mount(HomeScreenshotCarousel, {
            props: { slides },
        });

        expect(wrapper.findAll('[data-test="mock-carousel-item"]')).toHaveLength(3);
        expect(wrapper.find('[aria-label="Atlas product screenshots"]').exists()).toBe(true);
        expect(wrapper.findAll('img').map((image) => image.attributes('src'))).toEqual([
            '/home/browse-civitai-most-reactions.png',
            '/home/browse-deviantart.png',
            '/home/browse-full-view.png',
        ]);
        expect(wrapper.findAll('img').map((image) => image.attributes('loading'))).toEqual([
            'lazy',
            'lazy',
            'lazy',
        ]);
        expect(wrapper.text()).toContain('Feed sorting');
        expect(wrapper.text()).toContain('Source browsing');
        expect(wrapper.text()).toContain('Full view');
    });
});
