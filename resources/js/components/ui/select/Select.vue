<script setup lang="ts">
import { computed, provide, ref } from 'vue';
import { Popover } from '../popover';
import { SelectContextValue, SelectProvider } from './SelectContext';

interface Props {
    modelValue?: string;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: '',
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const open = ref(false);

const contextValue = computed<SelectContextValue>(() => ({
    modelValue: props.modelValue,
    disabled: props.disabled,
    onValueChange: (value: string) => {
        emit('update:modelValue', value);
        open.value = false;
    },
    open: open.value,
    setOpen: (value: boolean) => {
        open.value = value;
    },
}));

provide(SelectProvider, contextValue);
</script>

<template>
    <Popover v-model="open">
        <slot />
    </Popover>
</template>

