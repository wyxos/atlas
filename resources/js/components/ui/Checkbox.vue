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
            class="w-4 h-4 rounded focus:ring-2 accent-smart-blue-500"
            @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
        />
        <span class="text-sm text-twilight-indigo-100">
            <slot />
        </span>
    </label>
</template>

