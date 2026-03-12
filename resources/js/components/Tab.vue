<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { X, Layers, Loader2, SquarePen } from 'lucide-vue-next';

interface Props {
    id: number | string;
    label: string;
    customLabel?: string | null;
    isActive?: boolean;
    isMinimized?: boolean;
    isLoading?: boolean; // Tab data loading (spinner)
    isMasonryLoading?: boolean; // Masonry loading (pill)
}

const emit = defineEmits<{
    click: [];
    close: [];
    rename: [customLabel: string | null];
}>();

const props = withDefaults(defineProps<Props>(), {
    customLabel: null,
    isActive: false,
    isMinimized: false,
    isLoading: false,
    isMasonryLoading: false,
});

const isEditingCustomLabel = ref(false);
const customLabelInput = ref<HTMLInputElement | null>(null);
const draftCustomLabel = ref(props.customLabel ?? '');

watch(() => props.customLabel, (nextCustomLabel) => {
    if (!isEditingCustomLabel.value) {
        draftCustomLabel.value = nextCustomLabel ?? '';
    }
});

function handleClick(event: MouseEvent | KeyboardEvent): void {
    // Middle click (button 1) should close the tab
    if (event instanceof MouseEvent && event.button === 1) {
        event.preventDefault();
        event.stopPropagation();
        emit('close');
        return;
    }
    emit('click');
}

function handleClose(event: MouseEvent): void {
    event.stopPropagation();
    emit('close');
}

function handleMouseDown(event: MouseEvent): void {
    // Prevent default middle click behavior (opening link in new tab)
    if (event.button === 1) {
        event.preventDefault();
    }
}

function startCustomLabelEdit(event: MouseEvent): void {
    event.preventDefault();
    event.stopPropagation();
    if (props.isMinimized) {
        return;
    }

    isEditingCustomLabel.value = true;
    draftCustomLabel.value = props.customLabel ?? '';
    void nextTick(() => {
        customLabelInput.value?.focus();
        customLabelInput.value?.select();
    });
}

function commitCustomLabelEdit(): void {
    if (!isEditingCustomLabel.value) {
        return;
    }

    isEditingCustomLabel.value = false;
    const nextCustomLabel = draftCustomLabel.value.trim();
    const normalizedCustomLabel = nextCustomLabel === '' ? null : nextCustomLabel;
    if ((props.customLabel ?? null) === normalizedCustomLabel) {
        return;
    }

    emit('rename', normalizedCustomLabel);
}

function cancelCustomLabelEdit(): void {
    isEditingCustomLabel.value = false;
    draftCustomLabel.value = props.customLabel ?? '';
}

function handleCustomLabelKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        event.preventDefault();
        commitCustomLabelEdit();
        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        cancelCustomLabelEdit();
    }
}
</script>

<template>
    <div
        @click="handleClick"
        @mousedown="handleMouseDown"
        :class="[
            'group flex items-center justify-between w-full min-h-10 px-2.5 py-1.5 rounded-md transition-all cursor-pointer select-none',
            isActive
                ? 'bg-smart-blue-600/80 text-white'
                : 'text-twilight-indigo-200 hover:bg-prussian-blue-700/50 hover:text-twilight-indigo-100',
        ]"
        :title="customLabel ? `${customLabel} - ${label}` : label"
        role="button"
        tabindex="0"
        @keydown.enter="handleClick"
        @keydown.space.prevent="handleClick"
    >
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <Layers class="w-4 h-4 shrink-0" />
            <div v-show="!isMinimized" class="min-w-0 flex-1 transition-opacity duration-200"
                :class="!isMinimized ? 'opacity-100' : 'opacity-0'">
                <div v-if="isEditingCustomLabel" class="min-w-0 flex flex-col gap-0.5" @click.stop @mousedown.stop>
                    <input
                        ref="customLabelInput"
                        v-model="draftCustomLabel"
                        type="text"
                        maxlength="255"
                        class="h-6 w-full rounded border border-white/20 bg-black/20 px-2 text-xs text-white outline-none placeholder:text-white/50"
                        placeholder="Custom label"
                        data-test="tab-custom-label-input"
                        @keydown="handleCustomLabelKeydown"
                        @blur="commitCustomLabelEdit"
                    >
                    <span class="truncate text-[10px] text-current/70">
                        {{ label }}
                    </span>
                </div>
                <div v-else class="min-w-0" @dblclick="startCustomLabelEdit">
                    <span class="block truncate text-xs font-medium whitespace-nowrap">
                        {{ customLabel ?? label }}
                    </span>
                    <span v-if="customLabel" class="block truncate text-[10px] text-current/70 whitespace-nowrap">
                        {{ label }}
                    </span>
                </div>
            </div>
            <!-- Tab data loading spinner (loading files associated with tab) -->
            <Loader2
                v-if="isLoading"
                :size="12"
                class="animate-spin shrink-0 transition-opacity duration-200"
                :class="!isMinimized ? 'opacity-100' : 'opacity-0'"
                data-test="tab-loading-indicator"
            />
            <!-- Masonry loading pill (loading more items from service) -->
            <div
                v-if="isMasonryLoading"
                class="flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-smart-blue-500/20 text-smart-blue-300 text-[10px] font-medium shrink-0"
                :class="!isMinimized ? 'opacity-100' : 'opacity-0'"
                data-test="tab-masonry-loading-indicator"
            >
                <Loader2 :size="10" class="animate-spin" />
            </div>
        </div>
        <div v-show="!isMinimized" class="flex items-center shrink-0" :class="!isMinimized ? 'opacity-100' : 'opacity-0'">
            <button
                type="button"
                @click.stop="startCustomLabelEdit"
                class="flex items-center justify-center h-6 w-6 rounded text-current opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-all hover:bg-black/20 focus:opacity-100 focus:outline-none shrink-0"
                aria-label="Edit tab label"
                data-test="tab-rename-button"
            >
                <SquarePen :size="13" />
            </button>
            <button
                type="button"
                @click.stop="handleClose"
                class="flex items-center justify-center h-6 w-6 rounded text-current opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-all hover:bg-black/20 focus:opacity-100 focus:outline-none shrink-0"
                aria-label="Close tab"
            >
                <X :size="14" />
            </button>
        </div>
    </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
