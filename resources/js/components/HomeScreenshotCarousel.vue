<script setup lang="ts">
import {
    Carousel,
    CarouselContent,
    CarouselItem,
    CarouselNext,
    CarouselPrevious,
} from '@/components/ui/carousel';
import type { HomeScreenshotSlide } from '@/types/homeScreenshot';

defineProps<{
    slides: HomeScreenshotSlide[];
}>();
</script>

<template>
    <Carousel
        class="h-full"
        aria-label="Atlas product screenshots"
        :opts="{ align: 'start', loop: true }"
        data-test="home-screenshot-carousel"
    >
        <CarouselContent class="h-full">
            <CarouselItem
                v-for="slide in slides"
                :key="slide.src"
                class="h-full"
            >
                <figure class="relative h-full min-h-[min(46svh,30rem)] overflow-hidden">
                    <img
                        :src="slide.src"
                        :alt="slide.alt"
                        width="1920"
                        height="1080"
                        class="h-full min-h-[min(46svh,30rem)] w-full object-cover object-left-top"
                        decoding="async"
                        loading="lazy"
                    >
                    <figcaption class="absolute bottom-4 left-4 z-10 rounded border border-white/15 bg-prussian-blue-950/80 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-smart-blue-100 backdrop-blur">
                        {{ slide.label }}
                    </figcaption>
                </figure>
            </CarouselItem>
        </CarouselContent>

        <CarouselPrevious class="left-4 border-white/20 bg-prussian-blue-950/80 text-white hover:bg-prussian-blue-900" />
        <CarouselNext class="right-4 border-white/20 bg-prussian-blue-950/80 text-white hover:bg-prussian-blue-900" />
    </Carousel>
</template>
