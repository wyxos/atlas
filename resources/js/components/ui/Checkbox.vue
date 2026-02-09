<script setup lang="ts">
interface Props {
    modelValue?: boolean;
    id?: string;
    required?: boolean;
    disabled?: boolean;
}

withDefaults(defineProps<Props>(), {
    modelValue: false,
    id: undefined,
    required: false,
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();
</script>

<template>
    <label class="flex items-center gap-2" :class="disabled ? 'opacity-50 cursor-not-allowed' : ''">
        <input
            :id="id"
            type="checkbox"
            :checked="modelValue"
            :required="required"
            :disabled="disabled"
            class="form-checkbox"
            @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
        />
        <span class="form-option-label">
            <slot />
        </span>
    </label>
</template>
