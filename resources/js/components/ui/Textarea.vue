<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    modelValue?: string;
    placeholder?: string;
    id?: string;
    rows?: number;
    required?: boolean;
    error?: string;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: '',
    placeholder: '',
    id: undefined,
    rows: 4,
    required: false,
    error: '',
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
    focus: [event: FocusEvent];
}>();

const textareaClasses = computed(() => {
    const baseClasses =
        'w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors resize-none bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-900 focus:border-smart-blue-600 focus:ring-smart-blue-600/20';
    const errorClasses = props.error ? 'border-danger-700' : '';
    return `${baseClasses} ${errorClasses}`;
});

function handleInput(event: Event): void {
    const target = event.target as HTMLTextAreaElement;
    emit('update:modelValue', target.value);
}

function handleFocus(event: FocusEvent): void {
    emit('focus', event);
}
</script>

<template>
    <div>
        <label v-if="$slots.label" :for="id" class="block text-sm font-medium mb-2 text-smart-blue-900">
            <slot name="label" />
        </label>
        <textarea
            :id="id"
            :value="modelValue"
            :placeholder="placeholder"
            :rows="rows"
            :required="required"
            :class="textareaClasses"
            @input="handleInput"
            @focus="handleFocus"
        />
        <p v-if="error" class="mt-1 text-sm text-danger-700">
            {{ error }}
        </p>
    </div>
</template>

