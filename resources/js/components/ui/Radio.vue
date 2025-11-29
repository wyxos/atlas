<script setup lang="ts">
interface Props {
    modelValue?: string;
    value: string;
    id?: string;
    name?: string;
    required?: boolean;
}

// eslint-disable-next-line @typescript-eslint/no-unused-vars
const props = withDefaults(defineProps<Props>(), {
    modelValue: '',
    id: undefined,
    name: undefined,
    required: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();
</script>

<template>
    <label class="flex items-center gap-2">
        <input
            :id="id"
            type="radio"
            :name="name"
            :value="value"
            :checked="modelValue === value"
            :required="required"
            class="w-4 h-4 focus:ring-2 accent-smart-blue-500"
            @change="emit('update:modelValue', value)"
        />
        <span class="text-sm text-twilight-indigo-100">
            <slot />
        </span>
    </label>
</template>

