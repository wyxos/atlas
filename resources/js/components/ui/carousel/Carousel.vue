<script setup lang="ts">
import type { EmblaCarouselType, EmblaOptionsType, EmblaPluginType } from 'embla-carousel';
import type { HTMLAttributes } from 'vue';
import emblaCarouselVue from 'embla-carousel-vue';
import { computed, provide, ref, watch } from 'vue';
import { cn } from '@/lib/utils';
import { carouselContextKey, type CarouselOrientation } from './useCarousel';

interface Props {
    opts?: EmblaOptionsType;
    plugins?: EmblaPluginType[];
    orientation?: CarouselOrientation;
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<Props>(), {
    orientation: 'horizontal',
});

const emblaOptions = computed<EmblaOptionsType>(() => ({
    ...props.opts,
    axis: props.orientation === 'vertical' ? 'y' : 'x',
}));

const emblaPlugins = computed(() => props.plugins ?? []);
const [carouselRef, api] = emblaCarouselVue(emblaOptions, emblaPlugins);
const orientation = computed(() => props.orientation);
const canScrollPrev = ref(false);
const canScrollNext = ref(false);

function updateScrollState(currentApi: EmblaCarouselType | undefined = api.value): void {
    canScrollPrev.value = currentApi?.canScrollPrev() ?? false;
    canScrollNext.value = currentApi?.canScrollNext() ?? false;
}

function scrollPrev(): void {
    api.value?.scrollPrev();
}

function scrollNext(): void {
    api.value?.scrollNext();
}

watch(api, (currentApi, _previousApi, onCleanup) => {
    updateScrollState(currentApi);

    if (!currentApi) {
        return;
    }

    currentApi.on('reInit', updateScrollState);
    currentApi.on('select', updateScrollState);

    onCleanup(() => {
        currentApi.off('reInit', updateScrollState);
        currentApi.off('select', updateScrollState);
    });
}, { immediate: true });

provide(carouselContextKey, {
    carouselRef,
    api,
    orientation,
    canScrollPrev,
    canScrollNext,
    scrollPrev,
    scrollNext,
});

defineExpose({
    api,
    scrollPrev,
    scrollNext,
});
</script>

<template>
    <div
        role="region"
        aria-roledescription="carousel"
        data-slot="carousel"
        :class="cn('relative', props.class)"
    >
        <slot />
    </div>
</template>
