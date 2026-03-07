<script setup lang="ts">
import { computed } from 'vue';
import { Plus } from 'lucide-vue-next';

export type ToastPreviewStripItem = {
    key: string | number;
    label: string | number;
    thumbnail?: string;
};

interface Props {
    items: ToastPreviewStripItem[];
    totalCount: number;
    danger?: boolean;
    maxVisible?: number;
    size?: number;
}

const props = withDefaults(defineProps<Props>(), {
    danger: false,
    maxVisible: 5,
    size: 64,
});

const visibleItems = computed(() => props.items.slice(0, props.maxVisible));
const hasMore = computed(() => props.totalCount > props.maxVisible);
const borderClass = computed(() => (props.danger ? 'border-danger-500/50' : 'border-twilight-indigo-500/50'));
const placeholderClass = computed(() =>
    props.danger
        ? 'bg-danger-500/20 text-white'
        : 'bg-twilight-indigo-500/20 text-twilight-indigo-300',
);
const plusClass = computed(() =>
    props.danger
        ? 'border-danger-500/50 bg-danger-500/20 text-white'
        : 'border-twilight-indigo-500/50 bg-twilight-indigo-500/20 text-twilight-indigo-300',
);

function getPreviewOffset(index: number): number {
    return (index * 20 / 100) * props.size;
}

const containerWidth = computed(() => {
    if (visibleItems.value.length === 0) {
        return `${props.size}px`;
    }

    let totalWidth = props.size;
    for (let index = 1; index < visibleItems.value.length; index++) {
        totalWidth += props.size - getPreviewOffset(index);
    }

    if (hasMore.value) {
        totalWidth += props.size;
    }

    return `${totalWidth}px`;
});
</script>

<template>
    <div class="relative flex shrink-0 items-center" :style="{ width: containerWidth, height: `${size}px` }">
        <div
            v-for="(item, index) in visibleItems"
            :key="item.key"
            class="relative rounded border-2 object-cover"
            :class="borderClass"
            :style="{
                width: `${size}px`,
                height: `${size}px`,
                zIndex: visibleItems.length - index,
                marginLeft: index === 0 ? '0' : `-${getPreviewOffset(index)}px`,
            }"
        >
            <img
                v-if="item.thumbnail"
                :src="item.thumbnail"
                :alt="`File ${item.label}`"
                class="size-full rounded object-cover"
            />
            <div
                v-else
                class="flex size-full items-center justify-center rounded"
                :class="placeholderClass"
            >
                <span class="text-xs">#{{ item.label }}</span>
            </div>
        </div>

        <div
            v-if="hasMore"
            class="relative ml-2 flex flex-col items-center justify-center rounded border-2"
            :class="plusClass"
            :style="{ width: `${size}px`, height: `${size}px` }"
        >
            <Plus class="size-6" />
            <span class="mt-1 text-xs font-bold">{{ totalCount - maxVisible }}</span>
        </div>
    </div>
</template>
