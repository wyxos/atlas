<script setup lang="ts">
import FileReactions from '@/components/audio/FileReactions.vue';

const { item } = defineProps<{
  item: any;
}>();

const emit = defineEmits<{
  (e: 'open', item: any): void;
  (e: 'favorite', item: any, event: Event): void;
  (e: 'like', item: any, event: Event): void;
  (e: 'dislike', item: any, event: Event): void;
  (e: 'laughed-at', item: any, event: Event): void;
}>();

function openModal() {
  if (!item) return;
  emit('open', item);
}
</script>

<template>
  <div class="relative group">
    <img :src="item?.preview" class="w-full object-cover cursor-zoom-in" @click="openModal" />
    <div class="absolute right-2 bottom-2 flex items-center gap-2">
      <FileReactions
        v-if="item && item.id"
        :file="{ id: item.id }"
        :size="18"
        @favorite="(e) => emit('favorite', item, e)"
        @like="(e) => emit('like', item, e)"
        @dislike="(e) => emit('dislike', item, e)"
        @laughed-at="(e) => emit('laughed-at', item, e)"
      />
    </div>
  </div>
