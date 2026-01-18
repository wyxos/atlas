<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';

const items = Array.from({ length: 1000 }, (_, index) => ({
    id: index + 1,
    name: `Demo Item ${index + 1}`,
    url: null as string | null,
    status: null as string | null,
}));

const ROW_HEIGHT = 28;
const SCROLL_IDLE_MS = 150;
const containerRef = ref<HTMLElement | null>(null);
const scrollTop = ref(0);
const containerHeight = ref(0);
const scrollIdleTimer = ref<ReturnType<typeof setTimeout> | null>(null);
const visibleRangeLabel = ref('Visible indices: --');

const totalHeight = computed(() => items.length * ROW_HEIGHT);
const startIndex = computed(() =>
    Math.max(0, Math.floor(scrollTop.value / ROW_HEIGHT)),
);
const endIndex = computed(() =>
    Math.min(
        items.length,
        Math.ceil((scrollTop.value + containerHeight.value) / ROW_HEIGHT),
    ),
);
const visibleItems = computed(() => items.slice(startIndex.value, endIndex.value));
const offsetY = computed(() => startIndex.value * ROW_HEIGHT);

function onScroll(event: Event) {
    const target = event.target as HTMLElement | null;
    if (!target) return;
    scrollTop.value = target.scrollTop;
    if (scrollIdleTimer.value) {
        clearTimeout(scrollIdleTimer.value);
    }
    scrollIdleTimer.value = setTimeout(() => {
        scrollIdleTimer.value = null;
        visibleRangeLabel.value = `Visible indices: ${startIndex.value} - ${Math.max(0, endIndex.value - 1)}`;
    }, SCROLL_IDLE_MS);
}

function updateContainerHeight() {
    if (!containerRef.value) return;
    containerHeight.value = containerRef.value.clientHeight;
}

onMounted(() => {
    updateContainerHeight();
    window.addEventListener('resize', updateContainerHeight);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', updateContainerHeight);
    if (scrollIdleTimer.value) {
        clearTimeout(scrollIdleTimer.value);
        scrollIdleTimer.value = null;
    }
});
</script>

<template>
    <div class="mb-8 rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700">
        <div class="border-b border-twilight-indigo-500/40 px-4 py-2 text-xs uppercase tracking-wide text-blue-slate-300 flex items-center justify-between">
            <span>Demo List</span>
            <span class="text-blue-slate-400">{{ visibleRangeLabel }}</span>
        </div>
        <div
            ref="containerRef"
            class="relative max-h-64 overflow-auto px-4 py-2"
            @scroll="onScroll"
        >
            <div class="relative w-full" :style="{ height: `${totalHeight}px` }">
                <div class="absolute left-0 right-0" :style="{ transform: `translateY(${offsetY}px)` }">
                    <div
                        v-for="item in visibleItems"
                        :key="item.id"
                        class="flex items-center gap-3 border-b border-twilight-indigo-500/20 text-sm text-twilight-indigo-100 last:border-b-0"
                        :style="{ height: `${ROW_HEIGHT}px` }"
                    >
                        <span class="text-blue-slate-300">#{{ item.id }}</span>
                        <span class="min-w-0 flex-1 truncate">{{ item.name }}</span>
                        <Skeleton
                            v-if="!item.url"
                            class="h-3 w-32 bg-prussian-blue-500/60"
                        />
                        <span v-else class="text-xs text-blue-slate-300">{{ item.url }}</span>
                        <Skeleton
                            v-if="!item.status"
                            class="h-3 w-16 bg-prussian-blue-500/60"
                        />
                        <span v-else class="text-xs text-blue-slate-300">{{ item.status }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
