import type { EmblaCarouselType } from 'embla-carousel';
import type { Ref } from 'vue';
import { inject } from 'vue';

export type CarouselOrientation = 'horizontal' | 'vertical';

export interface CarouselContext {
    carouselRef: Ref<HTMLElement | undefined>;
    api: Ref<EmblaCarouselType | undefined>;
    orientation: Readonly<Ref<CarouselOrientation>>;
    canScrollPrev: Readonly<Ref<boolean>>;
    canScrollNext: Readonly<Ref<boolean>>;
    scrollPrev: () => void;
    scrollNext: () => void;
}

export const carouselContextKey = Symbol('carouselContext');

export function useCarousel(): CarouselContext {
    const context = inject<CarouselContext | null>(carouselContextKey, null);

    if (!context) {
        throw new Error('useCarousel must be used within a Carousel component.');
    }

    return context;
}
