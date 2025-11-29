<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { DateValue } from '@internationalized/date';
import { CalendarDate, getLocalTimeZone, today, toCalendarDate } from '@internationalized/date';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';

interface Props {
    modelValue?: DateValue | Date | null;
    defaultPlaceholder?: DateValue;
    layout?: 'month-and-year' | 'year';
    initialFocus?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: null,
    defaultPlaceholder: undefined,
    layout: 'month-and-year',
    initialFocus: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: DateValue | null];
}>();

const localTimeZone = getLocalTimeZone();
const placeholder = computed(() => props.defaultPlaceholder || today(localTimeZone));

const selectedDate = computed(() => {
    if (!props.modelValue) {
        return null;
    }
    if (props.modelValue instanceof Date) {
        return toCalendarDate(props.modelValue);
    }
    return props.modelValue;
});

const currentMonth = ref(selectedDate.value || placeholder.value);

// Update currentMonth when selectedDate changes
watch(selectedDate, (newDate) => {
    if (newDate) {
        currentMonth.value = newDate;
    }
}, { immediate: true });

const daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const calendarDays = computed(() => {
    const year = currentMonth.value.year;
    const month = currentMonth.value.month;
    const firstDay = new CalendarDate(year, month, 1);
    const startDate = firstDay.subtract({ days: firstDay.dayOfWeek });
    const days: CalendarDate[] = [];
    
    for (let i = 0; i < 42; i++) {
        days.push(startDate.add({ days: i }));
    }
    
    return days;
});

function selectDate(date: CalendarDate): void {
    emit('update:modelValue', date);
}

function isSelected(date: CalendarDate): boolean {
    if (!selectedDate.value) {
        return false;
    }
    return (
        date.year === selectedDate.value.year &&
        date.month === selectedDate.value.month &&
        date.day === selectedDate.value.day
    );
}

function isCurrentMonth(date: CalendarDate): boolean {
    return date.month === currentMonth.value.month && date.year === currentMonth.value.year;
}

function previousMonth(): void {
    currentMonth.value = currentMonth.value.subtract({ months: 1 });
}

function nextMonth(): void {
    currentMonth.value = currentMonth.value.add({ months: 1 });
}

function previousYear(): void {
    currentMonth.value = currentMonth.value.subtract({ years: 1 });
}

function nextYear(): void {
    currentMonth.value = currentMonth.value.add({ years: 1 });
}

const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];
</script>

<template>
    <div class="rounded-lg border-2 border-twilight-indigo-500 bg-prussian-blue-700 p-4">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <Button
                    v-if="layout === 'month-and-year'"
                    @click="previousMonth"
                    variant="ghost"
                    size="icon-sm"
                    class="text-twilight-indigo-100"
                    aria-label="Previous month"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Button>
                <Button
                    v-else
                    @click="previousYear"
                    variant="ghost"
                    size="icon-sm"
                    class="text-twilight-indigo-100"
                    aria-label="Previous year"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Button>
                <div class="text-sm font-semibold text-smart-blue-100 min-w-[120px] text-center">
                    {{ monthNames[currentMonth.month - 1] }} {{ currentMonth.year }}
                </div>
                <Button
                    v-if="layout === 'month-and-year'"
                    @click="nextMonth"
                    variant="ghost"
                    size="icon-sm"
                    class="text-twilight-indigo-100"
                    aria-label="Next month"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </Button>
                <Button
                    v-else
                    @click="nextYear"
                    variant="ghost"
                    size="icon-sm"
                    class="text-twilight-indigo-100"
                    aria-label="Next year"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </Button>
            </div>
        </div>
        
        <div class="grid grid-cols-7 gap-1 mb-2">
            <div
                v-for="day in daysOfWeek"
                :key="day"
                class="text-xs font-medium text-center text-twilight-indigo-700 py-1"
            >
                {{ day }}
            </div>
        </div>
        
        <div class="grid grid-cols-7 gap-1">
            <Button
                v-for="date in calendarDays"
                :key="`${date.year}-${date.month}-${date.day}`"
                @click.stop="selectDate(date)"
                variant="ghost"
                size="icon"
                :class="cn(
                    'text-sm transition-all duration-150',
                    isSelected(date)
                        ? 'bg-smart-blue-500 text-white font-semibold hover:bg-smart-blue-600 shadow-lg scale-105'
                        : isCurrentMonth(date)
                        ? 'text-twilight-indigo-100 hover:bg-smart-blue-500 hover:text-white hover:shadow-md hover:scale-105'
                        : 'text-twilight-indigo-600 hover:bg-smart-blue-500/80 hover:text-white hover:shadow-md hover:scale-105'
                )"
            >
                {{ date.day }}
            </Button>
        </div>
    </div>
</template>

