<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    modelValue?: string;
    type?: string;
    placeholder?: string;
    id?: string;
    required?: boolean;
    error?: string;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: '',
    type: 'text',
    placeholder: '',
    id: undefined,
    required: false,
    error: '',
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
    focus: [event: FocusEvent];
}>();

const inputClasses = computed(() => {
    const baseClasses =
        'w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-100 focus:border-smart-blue-400 focus:ring-smart-blue-400/20';
    const errorClasses = props.error ? 'border-danger-700' : '';
    return `${baseClasses} ${errorClasses}`;
});

function handleInput(event: Event): void {
    const target = event.target as HTMLInputElement;
    emit('update:modelValue', target.value);
}

function handleFocus(event: FocusEvent): void {
    emit('focus', event);
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        // Find the closest form and submit it
        const form = (event.target as HTMLElement).closest('form');
        if (form) {
            event.preventDefault();
            form.requestSubmit();
        }
    }
}
</script>

<template>
    <div>
        <label v-if="$slots.label" :for="id" class="block text-sm font-medium mb-2 text-smart-blue-100">
            <slot name="label" />
        </label>
        <input
            :id="id"
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            :required="required"
            :class="inputClasses"
            @input="handleInput"
            @focus="handleFocus"
            @keydown="handleKeydown"
        />
        <p v-if="error" class="mt-1 text-sm text-danger-700">
            {{ error }}
        </p>
    </div>
</template>


