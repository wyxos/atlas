<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { DateValue } from '@internationalized/date';
import { CalendarDate, DateFormatter, getLocalTimeZone, today } from '@internationalized/date';
import { CalendarIcon } from 'lucide-vue-next';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import Calendar from './Calendar.vue';
import { Popover, PopoverContent, PopoverTrigger } from './popover';

interface Props {
    modelValue?: string;
    placeholder?: string;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: '',
    placeholder: 'Pick a date',
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const defaultPlaceholder = today(getLocalTimeZone());

// Local state to track the selected date string
// This ensures immediate UI update regardless of parent reactivity timing
const localValue = ref(props.modelValue || '');

// Sync local value with prop changes from parent
watch(
    () => props.modelValue,
    (newValue) => {
        localValue.value = newValue || '';
    }
);

function parseStringToDate(value: string): CalendarDate | null {
    if (!value) {
        return null;
    }
    try {
        // Handle YYYY-MM-DD format
        const dateStr = String(value);
        const parts = dateStr.split('-');
        if (parts.length === 3) {
            const year = parseInt(parts[0], 10);
            const month = parseInt(parts[1], 10);
            const day = parseInt(parts[2], 10);
            if (!isNaN(year) && !isNaN(month) && !isNaN(day)) {
                return new CalendarDate(year, month, day);
            }
        }
        // Fallback to Date parsing - convert JS Date to CalendarDate
        const dateObj = new Date(value);
        if (!isNaN(dateObj.getTime())) {
            return new CalendarDate(
                dateObj.getFullYear(),
                dateObj.getMonth() + 1,
                dateObj.getDate()
            );
        }
        return null;
    } catch {
        return null;
    }
}

// Use local value for immediate updates
const date = computed(() => parseStringToDate(localValue.value));

const df = new DateFormatter('en-US', {
    dateStyle: 'long',
});

const displayValue = computed(() => {
    if (!date.value) {
        return props.placeholder;
    }
    try {
        const dateObj = date.value.toDate(getLocalTimeZone());
        if (!isNaN(dateObj.getTime())) {
            return df.format(dateObj);
        }
        return props.placeholder;
    } catch {
        return props.placeholder;
    }
});

function handleDateSelect(value: DateValue | null): void {
    if (value) {
        try {
            const dateObj = value.toDate(getLocalTimeZone());
            if (!isNaN(dateObj.getTime())) {
                const year = dateObj.getFullYear();
                const month = String(dateObj.getMonth() + 1).padStart(2, '0');
                const day = String(dateObj.getDate()).padStart(2, '0');
                const dateString = `${year}-${month}-${day}`;
                // Update local state immediately for instant UI feedback
                localValue.value = dateString;
                // Emit to parent
                emit('update:modelValue', dateString);
            } else {
                localValue.value = '';
                emit('update:modelValue', '');
            }
        } catch {
            localValue.value = '';
            emit('update:modelValue', '');
        }
    } else {
        localValue.value = '';
        emit('update:modelValue', '');
    }
}
</script>

<template>
    <Popover v-slot="{ close }">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="outline"
                :class="cn(
                    'w-full justify-start text-left font-normal',
                    !date && 'text-twilight-indigo-300'
                )"
            >
                <CalendarIcon :size="16" class="mr-2" />
                {{ displayValue }}
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" align="start">
            <Calendar
                :model-value="date"
                :default-placeholder="defaultPlaceholder"
                layout="month-and-year"
                @update:model-value="(value) => { 
                    handleDateSelect(value);
                    close();
                }"
            />
        </PopoverContent>
    </Popover>
</template>
