<script setup lang="ts">
import { computed } from 'vue';
import { Minus, Plus } from 'lucide-vue-next';
import { DEFAULT_WIDGET_MIN_IMAGE_WIDTH } from './site-customizations';

const props = defineProps<{
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const parsedMinImageWidth = computed(() => {
    const text = props.modelValue.trim();

    return /^\d+$/.test(text) ? Number(text) : null;
});
const canDecrementMinImageWidth = computed(() => parsedMinImageWidth.value !== 0);

function setMinImageWidthText(value: string): void {
    emit('update:modelValue', value.replace(/\D+/g, ''));
}

function stepMinImageWidth(delta: number): void {
    const current = parsedMinImageWidth.value ?? DEFAULT_WIDGET_MIN_IMAGE_WIDTH;
    emit('update:modelValue', String(Math.max(0, current + delta)));
}
</script>

<template>
    <div class="space-y-4 pt-4" data-test-customization-panel="widget">
        <label class="block max-w-md space-y-2">
            <span class="text-xs font-semibold uppercase tracking-[0.24em] text-smart-blue-200">Min Image Width</span>
            <div class="flex w-full items-stretch overflow-hidden rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/55 focus-within:border-smart-blue-300">
                <button
                    type="button"
                    class="inline-flex size-12 shrink-0 items-center justify-center border-r border-smart-blue-500/25 text-smart-blue-100 transition hover:bg-smart-blue-500/15 disabled:cursor-not-allowed disabled:text-twilight-indigo-500 disabled:hover:bg-transparent"
                    data-test-widget-min-image-width-decrement
                    :disabled="!canDecrementMinImageWidth"
                    aria-label="Decrease minimum image width"
                    title="Decrease"
                    @click="stepMinImageWidth(-1)"
                >
                    <Minus class="size-4" aria-hidden="true" />
                </button>
                <input
                    :value="modelValue"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    placeholder="200"
                    data-test-widget-min-image-width
                    class="min-w-0 grow bg-transparent px-4 py-3 text-center text-sm text-regal-navy-100 outline-none placeholder:text-twilight-indigo-400"
                    @input="setMinImageWidthText(($event.target as HTMLInputElement).value)"
                />
                <button
                    type="button"
                    class="inline-flex size-12 shrink-0 items-center justify-center border-l border-smart-blue-500/25 text-smart-blue-100 transition hover:bg-smart-blue-500/15"
                    data-test-widget-min-image-width-increment
                    aria-label="Increase minimum image width"
                    title="Increase"
                    @click="stepMinImageWidth(1)"
                >
                    <Plus class="size-4" aria-hidden="true" />
                </button>
            </div>
        </label>

        <div class="rounded-sm border border-smart-blue-500/20 bg-prussian-blue-900/25 px-4 py-3 text-sm text-twilight-indigo-300">
            Leave blank to use the global 200px image threshold. Use 0 when this profile should allow widgets on every loaded image size.
        </div>
    </div>
</template>
