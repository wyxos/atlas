<script setup lang="ts">
import { ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Loader2 } from 'lucide-vue-next';
import debounce from 'lodash/debounce';
import { router } from '@inertiajs/vue3';

const props = defineProps<{
  initialQuery?: string;
}>();

const query = ref(props.initialQuery || '');
const isLoading = ref(false);
const showNoResults = ref(false);

// Debounced search function
const debouncedSearch = debounce((newQuery: string|null, oldQuery: string|null) => {
  // Reset no results flag when starting a new search
  showNoResults.value = false;

  if (newQuery && newQuery.trim()) {
    isLoading.value = true;
    router.get(route('audio'), { query: newQuery }, {
      preserveState: true,
      only: ['search'],
      replace: true,
      onSuccess: (page) => {
        isLoading.value = false;
        // Check if search results are empty after a delay
        setTimeout(() => {
          // Check if search results are empty
          if (page.props.search && page.props.search.length === 0) {
            showNoResults.value = true;
          } else {
            showNoResults.value = false;
          }
        }, 1000); // 1 second delay before showing "no match found"
      },
      onError: (error) => {
        console.error('Search error:', error);
        isLoading.value = false;
        showNoResults.value = false;
      }
    });
  }

  if(oldQuery && !newQuery) {
    // If query is cleared, reset search results
    isLoading.value = true;
    showNoResults.value = false;
    router.get(route('audio'), {}, {
      preserveState: true,
      only: ['search'],
      replace: true,
      onSuccess: () => {
        isLoading.value = false;
        showNoResults.value = false;
      },
      onError: (error) => {
        console.error('Reset error:', error);
        isLoading.value = false;
        showNoResults.value = false;
      }
    });
  }
}, 500); // 500ms delay

watch(query, (newQuery, oldQuery) => {
  debouncedSearch(newQuery, oldQuery);
});
</script>

<template>
  <div class="p-4">
    <Input type="search" placeholder="Search" v-model="query" />
  </div>

  <!-- Loading spinner with "Searching..." text -->
  <div v-if="isLoading" class="flex flex-col justify-center items-center h-[640px]">
    <Loader2 class="animate-spin mb-2" :size="40" />
    <p class="text-gray-500">Searching...</p>
  </div>

  <!-- No results message -->
  <div v-else-if="query && showNoResults && $slots.noResults" class="flex justify-center items-center h-[640px]">
    <slot name="noResults" :query="query"></slot>
  </div>

  <!-- Results list -->
  <slot v-else :query="query"></slot>
</template>
