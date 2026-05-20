<script setup lang="ts">
import { computed } from 'vue';
import type { FileMetadataValue } from '@/types/file';

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

function isRecord(value: FileMetadataValue): value is Record<string, FileMetadataValue> {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function formatMetadataLabel(key: string): string {
    return key
        .replace(/^_+/, '')
        .replace(/[_-]+/g, ' ')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
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
</script>

<template>
    <div v-if="isRecordValue" class="space-y-2">
        <div
            v-for="[key, childValue] in recordEntries"
            :key="key"
            class="rounded border border-twilight-indigo-500/35 bg-prussian-blue-900/35 p-2"
        >
            <div class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-twilight-indigo-100">
                {{ formatMetadataLabel(key) }}
            </div>
            <FileViewerMetadataTree :value="childValue" :level="level + 1" />
        </div>
    </div>
    <div v-else-if="isArrayValue" class="space-y-2">
        <div
            v-for="{ index, item } in arrayEntries"
            :key="index"
            class="rounded border border-twilight-indigo-500/30 bg-prussian-blue-900/25 p-2"
        >
            <div class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-twilight-indigo-100">
                Item {{ index + 1 }}
            </div>
            <FileViewerMetadataTree :value="item" :level="level + 1" />
        </div>
    </div>
    <div
        v-else
        class="break-all rounded bg-black/20 px-2 py-1 font-mono text-xs leading-relaxed text-twilight-indigo-100"
        :class="{ 'text-twilight-indigo-300': value === null }"
    >
        {{ formatScalar(value) }}
    </div>
</template>
