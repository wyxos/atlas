<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type VirtualListItem = unknown;

const props = withDefaults(defineProps<{
    items: VirtualListItem[];
    itemHeight: number;
    containerClass?: string;
    overscan?: number;
}>(), {
    containerClass: 'flex-1 overflow-auto',
    overscan: 0,
});

const emit = defineEmits<{
    (e: 'scroll', top: number): void;
    (e: 'visible-items-change', items: VirtualListItem[]): void;
}>();

const containerRef = ref<HTMLElement | null>(null);
const scrollTop = ref(0);
const containerHeight = ref(0);
let resizeObserver: ResizeObserver | null = null;

const startIndex = computed(() =>
    Math.max(0, Math.floor(scrollTop.value / props.itemHeight) - props.overscan),
);
const endIndex = computed(() =>
    Math.min(
        props.items.length,
        Math.ceil((scrollTop.value + (containerHeight.value || props.itemHeight * 10)) / props.itemHeight) + props.overscan,
    ),
);
const visibleItems = computed(() => props.items.slice(startIndex.value, endIndex.value));
const totalHeight = computed(() => props.items.length * props.itemHeight);
const offsetY = computed(() => startIndex.value * props.itemHeight);

function updateContainerHeight() {
    if (!containerRef.value) {
        return;
    }

    containerHeight.value = containerRef.value.clientHeight;
}

function onScroll(event: Event) {
    const target = event.target as HTMLElement | null;
    if (!target) {
        return;
    }

    scrollTop.value = target.scrollTop;
    emit('scroll', target.scrollTop);
}

function resetScroll() {
    scrollTop.value = 0;
    if (containerRef.value) {
        containerRef.value.scrollTop = 0;
    }
}

watch(
    visibleItems,
    (items) => {
        emit('visible-items-change', items);
    },
    { immediate: true },
);

onMounted(() => {
    updateContainerHeight();

    if (containerRef.value) {
        resizeObserver = new ResizeObserver(() => {
            updateContainerHeight();
        });
        resizeObserver.observe(containerRef.value);
    }
});

onBeforeUnmount(() => {
    if (resizeObserver) {
        resizeObserver.disconnect();
        resizeObserver = null;
    }
});

defineExpose({
    resetScroll,
});
</script>

<template>
    <div ref="containerRef" :class="containerClass" @scroll="onScroll">
        <div class="relative w-full" :style="{ height: `${totalHeight}px` }">
            <div class="absolute left-0 right-0" :style="{ transform: `translateY(${offsetY}px)` }">
                <slot
                    :items="visibleItems"
                    :start-index="startIndex"
                    :end-index="endIndex"
                />
            </div>
        </div>
    </div>
</template>
