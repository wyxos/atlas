<script setup lang="ts">
import { computed } from 'vue';
import { X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

type PillVariant = 'primary' | 'secondary' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';

type PillSize = 'sm' | 'default' | 'lg';

type DismissStyle = 'default' | 'danger';

const props = withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        variant?: PillVariant;
        size?: PillSize;
        reversed?: boolean;
        dismissible?: boolean | DismissStyle;
    }>(),
    {
        variant: 'primary',
        size: 'default',
        reversed: false,
        dismissible: false,
    }
);

const emit = defineEmits<{
    dismiss: [];
}>();

const variantClasses = {
    primary: {
        border: 'border-smart-blue-500',
        labelBg: 'bg-smart-blue-600',
        labelHover: 'hover:bg-smart-blue-500',
        labelText: 'text-white',
        valueBg: 'bg-prussian-blue-700',
        valueText: 'text-twilight-indigo-100',
        valueHover: 'hover:bg-prussian-blue-600',
        borderSide: 'border-smart-blue-500',
    },
    secondary: {
        border: 'border-sapphire-500',
        labelBg: 'bg-sapphire-600',
        labelHover: 'hover:bg-sapphire-500',
        labelText: 'text-white',
        valueBg: 'bg-prussian-blue-700',
        valueText: 'text-twilight-indigo-100',
        valueHover: 'hover:bg-prussian-blue-600',
        borderSide: 'border-sapphire-500',
    },
    success: {
        border: 'border-success-500',
        labelBg: 'bg-success-600',
        labelHover: 'hover:bg-success-500',
        labelText: 'text-white',
        valueBg: 'bg-prussian-blue-700',
        valueText: 'text-success-100',
        valueHover: 'hover:bg-prussian-blue-600',
        borderSide: 'border-success-500',
    },
    warning: {
        border: 'border-warning-500',
        labelBg: 'bg-warning-600',
        labelHover: 'hover:bg-warning-500',
        labelText: 'text-black',
        valueBg: 'bg-prussian-blue-700',
        valueText: 'text-warning-100',
        valueHover: 'hover:bg-prussian-blue-600',
        borderSide: 'border-warning-500',
    },
    danger: {
        border: 'border-danger-500',
        labelBg: 'bg-danger-600',
        labelHover: 'hover:bg-danger-500',
        labelText: 'text-white',
        valueBg: 'bg-prussian-blue-700',
        valueText: 'text-twilight-indigo-100',
        valueHover: 'hover:bg-prussian-blue-600',
        borderSide: 'border-danger-500',
    },
    info: {
        border: 'border-info-500',
        labelBg: 'bg-info-600',
        labelHover: 'hover:bg-info-500',
        labelText: 'text-white',
        valueBg: 'bg-prussian-blue-700',
        valueText: 'text-info-100',
        valueHover: 'hover:bg-prussian-blue-600',
        borderSide: 'border-info-500',
    },
    neutral: {
        border: 'border-twilight-indigo-500',
        labelBg: 'bg-prussian-blue-600',
        labelHover: 'hover:bg-prussian-blue-500',
        labelText: 'text-twilight-indigo-100',
        valueBg: 'bg-prussian-blue-700',
        valueText: 'text-twilight-indigo-100',
        valueHover: 'hover:bg-prussian-blue-600',
        borderSide: 'border-twilight-indigo-500',
    },
};

const sizeClasses = {
    sm: {
        padding: 'px-2 py-0.5',
        text: 'text-[10px]',
    },
    default: {
        padding: 'px-3 py-1',
        text: 'text-xs',
    },
    lg: {
        padding: 'px-4 py-1.5',
        text: 'text-sm',
    },
};

const variant = computed(() => variantClasses[props.variant]);
const size = computed(() => sizeClasses[props.size]);

const labelClasses = computed(() => {
    const base = `${size.value.padding} ${size.value.text} font-medium transition-colors`;
    // When reversed, label gets the dark background (valueBg), value gets the colored background (labelBg)
    const bg = props.reversed ? variant.value.valueBg : variant.value.labelBg;
    const hover = props.reversed ? variant.value.valueHover : variant.value.labelHover;
    const text = props.reversed ? variant.value.valueText : variant.value.labelText;
    const border = `border-r ${variant.value.borderSide}`;

    return `${base} ${bg} ${hover} ${text} ${border}`;
});

const valueClasses = computed(() => {
    const base = `${size.value.padding} ${size.value.text} font-semibold transition-colors`;
    // When reversed, value gets the colored background (labelBg), label gets the dark background (valueBg)
    const bg = props.reversed ? variant.value.labelBg : variant.value.valueBg;
    const hover = props.reversed ? variant.value.labelHover : variant.value.valueHover;
    const text = props.reversed ? variant.value.labelText : variant.value.valueText;
    const border = `border-l ${variant.value.borderSide}`;

    return `${base} ${bg} ${hover} ${text} ${border}`;
});

const isDismissible = computed(() => {
    return props.dismissible !== false;
});

const dismissStyle = computed(() => {
    return props.dismissible === true ? 'default' : (props.dismissible as DismissStyle);
});

const dismissButtonVariant = computed(() => {
    return dismissStyle.value === 'danger' ? 'destructive' : 'ghost';
});

const dismissButtonSize = computed(() => {
    if (props.size === 'sm') return 'icon-sm';
    if (props.size === 'lg') return 'icon-lg';
    return 'icon';
});

const dismissButtonClasses = computed(() => {
    // Override Button component's fixed icon sizes to stretch with pill
    const base = `${size.value.text} font-bold border-l transition-colors rounded-none h-auto self-stretch flex items-center justify-center`;
    
    if (dismissStyle.value === 'danger') {
        return `${base} border-danger-500 px-2`;
    }
    
    const hoverClasses = {
    primary: 'hover:bg-smart-blue-600/40',
    secondary: 'hover:bg-sapphire-600/40',
    success: 'hover:bg-success-600/40',
    warning: 'hover:bg-warning-600/40',
    danger: 'hover:bg-danger-600/40',
    info: 'hover:bg-info-600/40',
    neutral: 'hover:bg-prussian-blue-600/60',
};

    return `${base} ${variant.value.borderSide} ${hoverClasses[props.variant]} hover:text-twilight-indigo-100 px-2`;
});

function handleDismiss(): void {
    emit('dismiss');
}
</script>

<template>
    <span class="inline-flex items-stretch rounded overflow-hidden border" :class="variant.border">
        <!-- Label (always on left) -->
        <span :class="labelClasses">
            <slot name="label">{{ label }}</slot>
        </span>

        <!-- Value (always on right) -->
        <span :class="valueClasses">
            <slot name="value">{{ value }}</slot>
        </span>

        <!-- Dismiss button -->
        <Button
            v-if="isDismissible"
            :variant="dismissButtonVariant"
            :size="dismissButtonSize"
            :class="dismissButtonClasses"
            :aria-label="dismissStyle === 'danger' ? 'Ban container' : 'Remove'"
            @click.stop="handleDismiss"
        >
            <X v-if="dismissStyle === 'danger'" :size="12" />
            <span v-else>×</span>
        </Button>
    </span>
</template>

