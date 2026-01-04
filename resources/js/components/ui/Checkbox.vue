<script setup lang="ts">
interface Props {
    modelValue?: boolean;
    id?: string;
    required?: boolean;
}

withDefaults(defineProps<Props>(), {
    modelValue: false,
    id: undefined,
    required: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();
</script>

<template>
    <label class="flex items-center gap-2">
        <input
            :id="id"
            type="checkbox"
            :checked="modelValue"
            :required="required"
            class="form-checkbox"
            @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
        />
        <span class="form-option-label">
            <slot />
        </span>
    </label>
</template>

