<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PageLayout from '../components/PageLayout.vue';

const STATUSES = ['queued', 'processing', 'done', 'failed'] as const;
type Status = typeof STATUSES[number];
const FILTERS = ['all', ...STATUSES] as const;
type FilterStatus = typeof FILTERS[number];
const IDS = Array.from({ length: 100 }, (_, index) => ({
    id: index + 1,
    status: STATUSES[index % STATUSES.length],
}));

const ITEM_HEIGHT = 48;
const OVERSCAN = 12;

const containerRef = ref<HTMLElement | null>(null);
const scrollTop = ref(0);
const containerHeight = ref(0);
const selectedStatus = ref<FilterStatus>('all');

const filteredIds = computed(() =>
    selectedStatus.value === 'all'
        ? IDS
        : IDS.filter((item) => item.status === selectedStatus.value),
);
const totalHeight = computed(() => filteredIds.value.length * ITEM_HEIGHT);
const startIndex = computed(() =>
    Math.max(0, Math.floor(scrollTop.value / ITEM_HEIGHT) - OVERSCAN),
);
const endIndex = computed(() =>
    Math.min(
        filteredIds.value.length,
        Math.ceil((scrollTop.value + containerHeight.value) / ITEM_HEIGHT) + OVERSCAN,
    ),
);
const visibleIds = computed(() => filteredIds.value.slice(startIndex.value, endIndex.value));
const offsetY = computed(() => startIndex.value * ITEM_HEIGHT);

const STATUS_STYLES: Record<Status, string> = {
    queued: 'bg-twilight-indigo-500 border border-blue-slate-500 text-twilight-indigo-100',
    processing: 'bg-smart-blue-600 border border-smart-blue-500 text-white',
    done: 'bg-success-600 border border-success-500 text-white',
    failed: 'bg-danger-600 border border-danger-500 text-white',
};

function statusClass(status: Status) {
    return STATUS_STYLES[status];
}

function filterLabel(status: FilterStatus) {
    if (status === 'all') return 'All';
    return status.charAt(0).toUpperCase() + status.slice(1);
}

function onScroll(event: Event) {
    const target = event.target as HTMLElement | null;
    if (!target) return;
    scrollTop.value = target.scrollTop;
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
});

watch(selectedStatus, () => {
    scrollTop.value = 0;
    if (containerRef.value) {
        containerRef.value.scrollTop = 0;
    }
});
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <h4 class="text-2xl font-semibold mb-2 text-regal-navy-100">
                        Downloads Queue
                    </h4>
                    <p class="text-blue-slate-300">
                        Manage queued downloads.
                    </p>
                </div>
            </div>

            <div class="mb-4 flex flex-wrap items-center gap-2">
                <button
                    v-for="status in FILTERS"
                    :key="status"
                    type="button"
                    class="inline-flex items-center rounded border px-3 py-1 text-xs font-semibold uppercase tracking-wide transition-colors"
                    :class="selectedStatus === status
                        ? 'border-smart-blue-500 bg-smart-blue-600 text-white'
                        : 'border-twilight-indigo-500 bg-prussian-blue-600 text-twilight-indigo-100 hover:bg-prussian-blue-500'"
                    @click="selectedStatus = status"
                >
                    {{ filterLabel(status) }}
                </button>
            </div>

            <div class="rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700 overflow-hidden">
                <div
                    class="flex items-center justify-between border-b border-twilight-indigo-500/40 px-4 py-2 text-xs uppercase tracking-wide text-blue-slate-300"
                >
                    <span>ID</span>
                    <span>Status</span>
                </div>
                <div
                    ref="containerRef"
                    class="min-h-[60vh] max-h-[70vh] overflow-auto"
                    @scroll="onScroll"
                >
                    <div class="relative w-full" :style="{ height: `${totalHeight}px` }">
                        <div class="absolute left-0 right-0" :style="{ transform: `translateY(${offsetY}px)` }">
                            <div
                                v-for="item in visibleIds"
                                :key="item.id"
                                class="flex h-12 items-center justify-between border-b border-twilight-indigo-500/20 px-4 text-sm text-twilight-indigo-100 transition-colors hover:bg-prussian-blue-600/60"
                            >
                                <span class="font-mono">ID {{ item.id }}</span>
                                <span
                                    class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium"
                                    :class="statusClass(item.status)"
                                >
                                    {{ item.status }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PageLayout>
</template>
