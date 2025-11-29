<script setup lang="ts">
import { computed } from 'vue';
import FormInput from './ui/FormInput.vue';
import DatePicker from './ui/DatePicker.vue';

interface Props {
    search: string;
    dateFrom: string;
    dateTo: string;
    searchPlaceholder?: string;
    dateRangeLabel?: string;
}

const props = withDefaults(defineProps<Props>(), {
    searchPlaceholder: 'Search...',
    dateRangeLabel: 'Created Date Range',
});

const emit = defineEmits<{
    'update:search': [value: string];
    'update:dateFrom': [value: string];
    'update:dateTo': [value: string];
    submit: [];
}>();

const searchModel = computed({
    get: () => props.search,
    set: (value: string) => emit('update:search', value),
});

const dateFromModel = computed({
    get: () => props.dateFrom,
    set: (value: string) => emit('update:dateFrom', value),
});

const dateToModel = computed({
    get: () => props.dateTo,
    set: (value: string) => emit('update:dateTo', value),
});
</script>

<template>
    <form @submit.prevent="$emit('submit')" class="space-y-6">
        <!-- Search Field -->
        <FormInput
            v-model="searchModel"
            :placeholder="searchPlaceholder"
        >
            <template #label>
                Search
            </template>
        </FormInput>

        <!-- Date Range -->
        <div>
            <label class="block text-sm font-medium mb-2 text-smart-blue-100">
                {{ dateRangeLabel }}
            </label>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1 text-twilight-indigo-700">
                        From
                    </label>
                    <DatePicker
                        v-model="dateFromModel"
                        placeholder="Pick start date"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1 text-twilight-indigo-700">
                        To
                    </label>
                    <DatePicker
                        v-model="dateToModel"
                        placeholder="Pick end date"
                    />
                </div>
            </div>
        </div>

        <!-- Additional Filters Slot -->
        <slot />
    </form>
</template>

