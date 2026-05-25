<script setup lang="ts">
import { computed } from 'vue';
import type { FileMetadataValue } from '@/types/file';
import { copyToClipboard } from '@/utils/clipboard';

defineOptions({
    name: 'FileViewerMetadataTree',
});

const props = withDefaults(defineProps<{
    value: FileMetadataValue;
    level?: number;
}>(), {
    level: 0,
});

const isArrayValue = computed(() => Array.isArray(props.value));
const isRecordValue = computed(() => isRecord(props.value));

const recordEntries = computed(() => {
    if (!isRecord(props.value)) {
        return [];
    }

    return Object.entries(props.value);
});

const arrayEntries = computed(() => {
    if (!Array.isArray(props.value)) {
        return [];
    }

    return props.value.map((item, index) => ({ index, item }));
});

const acronymLabels = new Set(['api', 'css', 'html', 'id', 'mime', 'nsfw', 'url']);

function isRecord(value: FileMetadataValue): value is Record<string, FileMetadataValue> {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isScalar(value: FileMetadataValue): value is string | number | boolean | null {
    return value === null || ['string', 'number', 'boolean'].includes(typeof value);
}

function formatMetadataLabel(key: string): string {
    return key
        .replace(/^_+/, '')
        .replace(/[_-]+/g, ' ')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/\b\w/g, (letter) => letter.toUpperCase())
        .split(' ')
        .map((word) => (acronymLabels.has(word.toLowerCase()) ? word.toUpperCase() : word))
        .join(' ');
}

function formatScalar(value: string | number | boolean | null): string {
    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (value === null) {
        return 'null';
    }

    if (value === '') {
        return '""';
    }

    return String(value);
}

function formatScalarValue(value: FileMetadataValue): string {
    return isScalar(value) ? formatScalar(value) : '';
}

function formatCollectionSummary(value: FileMetadataValue): string {
    if (Array.isArray(value)) {
        return `${value.length} ${value.length === 1 ? 'item' : 'items'}`;
    }

    if (isRecord(value)) {
        const count = Object.keys(value).length;

        return `${count} ${count === 1 ? 'field' : 'fields'}`;
    }

    return '';
}

async function copyScalar(value: FileMetadataValue, label: string): Promise<void> {
    if (!isScalar(value)) {
        return;
    }

    await copyToClipboard(formatScalar(value), label, { showToast: false });
}
</script>

<template>
    <div v-if="isRecordValue" class="space-y-1" data-testid="metadata-tree">
        <div
            v-for="([key, childValue], index) in recordEntries"
            :key="key"
            class="relative pl-4"
            data-testid="metadata-tree-branch"
        >
            <span
                aria-hidden="true"
                class="absolute left-1 top-0 w-px bg-twilight-indigo-500/35"
                :class="index === recordEntries.length - 1 ? 'h-3' : 'h-full'"
                data-testid="metadata-tree-connector"
            />
            <span
                aria-hidden="true"
                class="absolute left-1 top-3 h-px w-3 bg-twilight-indigo-500/35"
            />

            <button
                v-if="isScalar(childValue)"
                type="button"
                class="group flex min-w-0 w-full items-center gap-3 rounded px-2 py-1 text-left transition-colors hover:bg-prussian-blue-700/55 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-dodger-blue-500"
                :title="formatScalarValue(childValue)"
                :aria-label="`Copy metadata ${formatMetadataLabel(key)}`"
                :data-label="formatMetadataLabel(key)"
                data-testid="metadata-scalar-copy"
                @click="copyScalar(childValue, formatMetadataLabel(key))"
            >
                <span
                    class="w-28 shrink-0 truncate text-[10px] font-semibold uppercase tracking-wide text-twilight-indigo-300"
                    data-testid="metadata-scalar-label"
                >
                    {{ formatMetadataLabel(key) }}
                </span>
                <span
                    class="min-w-0 flex-1 truncate font-mono text-xs leading-5 text-twilight-indigo-100 group-hover:text-white"
                    :class="{ 'text-twilight-indigo-400': childValue === null }"
                    data-testid="metadata-scalar-value"
                >
                    {{ formatScalarValue(childValue) }}
                </span>
            </button>

            <div v-else class="rounded px-2 py-1">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="min-w-0 truncate text-[10px] font-semibold uppercase tracking-wide text-twilight-indigo-100">
                        {{ formatMetadataLabel(key) }}
                    </span>
                    <span class="shrink-0 text-[10px] text-twilight-indigo-400">
                        {{ formatCollectionSummary(childValue) }}
                    </span>
                </div>
                <div class="mt-1">
                    <FileViewerMetadataTree :value="childValue" :level="level + 1" />
                </div>
            </div>
        </div>
    </div>
    <div v-else-if="isArrayValue" class="space-y-1" data-testid="metadata-tree">
        <div
            v-for="({ index, item }, arrayIndex) in arrayEntries"
            :key="index"
            class="relative pl-4"
            data-testid="metadata-tree-branch"
        >
            <span
                aria-hidden="true"
                class="absolute left-1 top-0 w-px bg-twilight-indigo-500/35"
                :class="arrayIndex === arrayEntries.length - 1 ? 'h-3' : 'h-full'"
                data-testid="metadata-tree-connector"
            />
            <span
                aria-hidden="true"
                class="absolute left-1 top-3 h-px w-3 bg-twilight-indigo-500/35"
            />

            <button
                v-if="isScalar(item)"
                type="button"
                class="group flex min-w-0 w-full items-center gap-3 rounded px-2 py-1 text-left transition-colors hover:bg-prussian-blue-700/55 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-dodger-blue-500"
                :title="formatScalarValue(item)"
                :aria-label="`Copy metadata Item ${index + 1}`"
                :data-label="`Item ${index + 1}`"
                data-testid="metadata-scalar-copy"
                @click="copyScalar(item, `Item ${index + 1}`)"
            >
                <span
                    class="w-28 shrink-0 truncate text-[10px] font-semibold uppercase tracking-wide text-twilight-indigo-300"
                    data-testid="metadata-scalar-label"
                >
                    Item {{ index + 1 }}
                </span>
                <span
                    class="min-w-0 flex-1 truncate font-mono text-xs leading-5 text-twilight-indigo-100 group-hover:text-white"
                    :class="{ 'text-twilight-indigo-400': item === null }"
                    data-testid="metadata-scalar-value"
                >
                    {{ formatScalarValue(item) }}
                </span>
            </button>

            <div v-else class="rounded px-2 py-1">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="min-w-0 truncate text-[10px] font-semibold uppercase tracking-wide text-twilight-indigo-100">
                        Item {{ index + 1 }}
                    </span>
                    <span class="shrink-0 text-[10px] text-twilight-indigo-400">
                        {{ formatCollectionSummary(item) }}
                    </span>
                </div>
                <div class="mt-1">
                    <FileViewerMetadataTree :value="item" :level="level + 1" />
                </div>
            </div>
        </div>
    </div>
    <button
        v-else
        type="button"
        class="group flex min-w-0 w-full items-center gap-3 rounded px-2 py-1 text-left transition-colors hover:bg-prussian-blue-700/55 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-dodger-blue-500"
        :title="formatScalarValue(value)"
        aria-label="Copy metadata value"
        data-label="Value"
        data-testid="metadata-scalar-copy"
        @click="copyScalar(value, 'Value')"
    >
        <span
            class="w-28 shrink-0 truncate text-[10px] font-semibold uppercase tracking-wide text-twilight-indigo-300"
            data-testid="metadata-scalar-label"
        >
            Value
        </span>
        <span
            class="min-w-0 flex-1 truncate font-mono text-xs leading-5 text-twilight-indigo-100 group-hover:text-white"
            :class="{ 'text-twilight-indigo-400': value === null }"
            data-testid="metadata-scalar-value"
        >
            {{ formatScalarValue(value) }}
        </span>
    </button>
</template>
