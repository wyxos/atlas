<script setup lang="ts">
import { ref, computed } from 'vue';
import type { DateValue } from '@internationalized/date';
import { CalendarDate, DateFormatter, getLocalTimeZone, today, toCalendarDate } from '@internationalized/date';
import { CalendarIcon } from 'lucide-vue-next';
import { cn } from '@/lib/utils';
import Button from './Button.vue';
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

const popoverOpen = ref(false);
const defaultPlaceholder = today(getLocalTimeZone());

const date = computed({
    get: () => {
        if (!props.modelValue) {
            return null;
        }
        try {
            // Handle YYYY-MM-DD format
            const dateStr = String(props.modelValue);
            const parts = dateStr.split('-');
            if (parts.length === 3) {
                const year = parseInt(parts[0], 10);
                const month = parseInt(parts[1], 10);
                const day = parseInt(parts[2], 10);
                if (!isNaN(year) && !isNaN(month) && !isNaN(day)) {
                    return new CalendarDate(year, month, day);
                }
            }
            // Fallback to Date parsing
            const dateObj = new Date(props.modelValue);
            if (!isNaN(dateObj.getTime())) {
                return toCalendarDate(dateObj);
            }
            return null;
        } catch {
            return null;
        }
    },
    set: (value: DateValue | null) => {
        if (value) {
            try {
                const dateObj = value.toDate(getLocalTimeZone());
                if (!isNaN(dateObj.getTime())) {
                    const year = dateObj.getFullYear();
                    const month = String(dateObj.getMonth() + 1).padStart(2, '0');
                    const day = String(dateObj.getDate()).padStart(2, '0');
                    emit('update:modelValue', `${year}-${month}-${day}`);
                } else {
                    emit('update:modelValue', '');
                }
            } catch {
                emit('update:modelValue', '');
            }
        } else {
            emit('update:modelValue', '');
        }
    },
});

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
    date.value = value;
    popoverOpen.value = false;
}
</script>

<template>
    <Popover v-model="popoverOpen">
        <PopoverTrigger>
            <Button
                type="button"
                variant="outline"
                :class="cn(
                    'w-full justify-start text-left font-normal',
                    !date && 'text-twilight-indigo-700'
                )"
            >
                <CalendarIcon class="mr-2 h-4 w-4" />
                {{ displayValue }}
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" align="start">
            <Calendar
                :model-value="date"
                :default-placeholder="defaultPlaceholder"
                layout="month-and-year"
                @update:model-value="handleDateSelect"
            />
        </PopoverContent>
    </Popover>
</template>


