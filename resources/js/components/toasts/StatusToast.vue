<script setup lang="ts">
import { computed } from 'vue';
import { CircleCheckBig, Info, TriangleAlert } from 'lucide-vue-next';
import { useToast } from 'vue-toastification';

type StatusToastVariant = 'success' | 'info' | 'error';

interface Props {
    toastId: string;
    title: string;
    description?: string;
    variant?: StatusToastVariant;
}

const props = withDefaults(defineProps<Props>(), {
    description: undefined,
    variant: 'info',
});

const toast = useToast();

const theme = computed(() => {
    if (props.variant === 'error') {
        return {
            container: 'status-toast group relative flex items-start gap-3 rounded-lg border border-danger-500/50 bg-danger-600 p-4 shadow-xl',
            iconWrapper: 'bg-white/20 text-white',
            title: 'text-danger-100',
            description: 'text-white/80',
            dismiss: 'shrink-0 rounded p-1 text-white transition-colors hover:bg-white/20 hover:text-white',
        };
    }

    if (props.variant === 'success') {
        return {
            container: 'status-toast group relative flex items-start gap-3 rounded-lg border border-smart-blue-500/50 bg-prussian-blue-600 p-4 shadow-xl',
            iconWrapper: 'bg-smart-blue-500/20 text-smart-blue-200',
            title: 'text-twilight-indigo-100',
            description: 'text-twilight-indigo-300',
            dismiss: 'shrink-0 rounded p-1 text-twilight-indigo-300 transition-colors hover:bg-twilight-indigo-500/20 hover:text-twilight-indigo-100',
        };
    }

    return {
        container: 'status-toast group relative flex items-start gap-3 rounded-lg border border-twilight-indigo-500/50 bg-prussian-blue-600 p-4 shadow-xl',
        iconWrapper: 'bg-smart-blue-500/20 text-smart-blue-200',
        title: 'text-twilight-indigo-100',
        description: 'text-twilight-indigo-300',
        dismiss: 'shrink-0 rounded p-1 text-twilight-indigo-300 transition-colors hover:bg-twilight-indigo-500/20 hover:text-twilight-indigo-100',
    };
});

const icon = computed(() => {
    if (props.variant === 'error') {
        return TriangleAlert;
    }

    if (props.variant === 'success') {
        return CircleCheckBig;
    }

    return Info;
});

function handleDismiss(): void {
    toast.dismiss(props.toastId);
}
</script>

<template>
    <div :class="[theme.container, 'flex! gap-3!']">
        <div :class="['shrink-0 rounded-full p-2', theme.iconWrapper]">
            <component :is="icon" class="size-4" />
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p :class="['text-sm font-semibold', theme.title]">
                        {{ title }}
                    </p>
                    <p v-if="description" :class="['mt-1 text-xs', theme.description]">
                        {{ description }}
                    </p>
                </div>

                <button
                    type="button"
                    :class="theme.dismiss"
                    aria-label="Dismiss"
                    @click="handleDismiss"
                >
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.status-toast {
    min-width: 300px;
    max-width: 460px;
}
</style>
