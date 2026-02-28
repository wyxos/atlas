<script setup lang="ts">
import { computed } from 'vue';

defineOptions({
  name: 'JsonTree',
});

type JsonEntry = {
  key: string;
  label: string | number;
  value: unknown;
};

const props = withDefaults(defineProps<{
  value: unknown;
  name?: string | number | null;
  expanded?: boolean;
}>(), {
  name: null,
  expanded: false,
});

const hasName = computed(() => props.name !== null && props.name !== undefined);

const childEntries = computed<JsonEntry[]>(() => {
  if (Array.isArray(props.value)) {
    return props.value.map((value, index) => ({
      key: String(index),
      label: index,
      value,
    }));
  }

  if (props.value && typeof props.value === 'object') {
    return Object.entries(props.value as Record<string, unknown>).map(([key, value]) => ({
      key,
      label: key,
      value,
    }));
  }

  return [];
});

function isExpandable(value: unknown): boolean {
  if (!value || typeof value !== 'object') {
    return false;
  }

  return Array.isArray(value) || Object.keys(value as Record<string, unknown>).length >= 0;
}

function previewFor(value: unknown): string {
  if (Array.isArray(value)) {
    return `[${value.length}]`;
  }

  if (value && typeof value === 'object') {
    return `{${Object.keys(value as Record<string, unknown>).length}}`;
  }

  return primitiveDisplay(value);
}

function formatName(value: string | number | null | undefined): string {
  if (value === null || value === undefined) {
    return '';
  }

  if (typeof value === 'number') {
    return `[${value}]`;
  }

  return `"${value}"`;
}

function primitiveType(value: unknown): 'string' | 'number' | 'boolean' | 'null' | 'other' {
  if (value === null) {
    return 'null';
  }

  if (typeof value === 'string') {
    return 'string';
  }

  if (typeof value === 'number') {
    return 'number';
  }

  if (typeof value === 'boolean') {
    return 'boolean';
  }

  return 'other';
}

function primitiveDisplay(value: unknown): string {
  if (value === null) {
    return 'null';
  }

  if (typeof value === 'string') {
    return JSON.stringify(value);
  }

  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }

  const serialized = JSON.stringify(value);
  return serialized ?? '"[undefined]"';
}
</script>

<template>
  <div class="atlas-downloader-json-tree">
    <details v-if="isExpandable(value)" class="atlas-downloader-json-node" :open="expanded">
      <summary>
        <span v-if="hasName" class="atlas-downloader-json-key">{{ formatName(name) }}</span>
        <span v-if="hasName">: </span>
        <span class="atlas-downloader-json-preview">{{ previewFor(value) }}</span>
      </summary>

      <div class="atlas-downloader-json-children">
        <JsonTree
          v-for="entry in childEntries"
          :key="entry.key"
          :name="entry.label"
          :value="entry.value"
          :expanded="false"
        />
      </div>
    </details>

    <div v-else class="atlas-downloader-json-line">
      <span v-if="hasName" class="atlas-downloader-json-key">{{ formatName(name) }}</span>
      <span v-if="hasName">: </span>
      <span class="atlas-downloader-json-value" :data-type="primitiveType(value)">
        {{ primitiveDisplay(value) }}
      </span>
    </div>
  </div>
</template>
