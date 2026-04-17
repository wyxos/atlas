<script setup lang="ts">
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Props {
    modelValue: string;
    options: readonly string[];
}

defineProps<Props>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();
</script>

<template>
    <div class="form-field">
        <label class="form-label">Limit</label>

        <Select :model-value="modelValue" @update:model-value="(value) => emit('update:modelValue', String(value))">
            <SelectTrigger class="w-full">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem v-for="option in options" :key="option" :value="option">
                    {{ option }}
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
