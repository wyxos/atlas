<script setup lang="ts">
import type { Listing } from '../lib/Listing';

interface Props {
    listing: Listing<Record<string, unknown>>;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    class: '',
});

function handlePageChange(page: number): void {
    props.listing.goToPage(page);
}
</script>

<template>
    <o-table
        v-bind="listing.config()"
        @page-change="handlePageChange"
        :class="['rounded-lg', props.class]"
    >
        <slot />
        <template #empty>
            <slot name="empty" />
        </template>
    </o-table>
</template>
