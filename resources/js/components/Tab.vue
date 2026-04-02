<script setup lang="ts">
import { nextTick, ref, useAttrs, watch } from 'vue';
import { Copy, X, Layers, Loader2, SquarePen } from 'lucide-vue-next';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    id: number | string;
    label: string;
    customLabel?: string | null;
    isActive?: boolean;
    isMinimized?: boolean;
    isLoading?: boolean;
    isMasonryLoading?: boolean;
    isDragging?: boolean;
    dropIndicator?: 'before' | 'after' | null;
    canCloseAbove?: boolean;
    canCloseBelow?: boolean;
    canCloseOthers?: boolean;
}

const emit = defineEmits<{
    click: [];
    close: [];
    rename: [customLabel: string | null];
    duplicate: [];
    'close-above': [];
    'close-below': [];
    'close-others': [];
    'drag-start': [];
    'drag-over': [side: 'before' | 'after'];
    'drag-drop': [side: 'before' | 'after'];
    'drag-end': [];
}>();

const props = withDefaults(defineProps<Props>(), {
    customLabel: null,
    isActive: false,
    isMinimized: false,
    isLoading: false,
    isMasonryLoading: false,
    isDragging: false,
    dropIndicator: null,
    canCloseAbove: false,
    canCloseBelow: false,
    canCloseOthers: false,
});

const attrs = useAttrs();
const isEditingCustomLabel = ref(false);
const customLabelInput = ref<HTMLInputElement | null>(null);
const draftCustomLabel = ref(props.customLabel ?? '');
const preventContextMenuCloseAutoFocus = ref(false);
const suppressClickUntil = ref(0);

watch(() => props.customLabel, (nextCustomLabel) => {
    if (!isEditingCustomLabel.value) {
        draftCustomLabel.value = nextCustomLabel ?? '';
    }
});

function handleClick(event: MouseEvent | KeyboardEvent): void {
    if (event instanceof MouseEvent && event.button === 1) {
        return;
    }

    if (Date.now() < suppressClickUntil.value) {
        return;
    }

    emit('click');
}

function handleClose(event: MouseEvent): void {
    event.stopPropagation();
    emit('close');
}

function handleMouseDown(event: MouseEvent): void {
    if (event.button === 1) {
        event.preventDefault();
        event.stopPropagation();
        emit('close');
    }
}

function startCustomLabelEdit(): void {
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

function queueCustomLabelEdit(): void {
    if (props.isMinimized) {
        return;
    }

    preventContextMenuCloseAutoFocus.value = true;
}

function handleContextMenuCloseAutoFocus(event: Event): void {
    if (!preventContextMenuCloseAutoFocus.value) {
        return;
    }

    event.preventDefault();
    preventContextMenuCloseAutoFocus.value = false;
    startCustomLabelEdit();
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

function clearCustomLabel(): void {
    if (props.isMinimized || props.customLabel === null) {
        return;
    }

    emit('rename', null);
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

function suppressNextClick(): void {
    suppressClickUntil.value = Date.now() + 150;
}

function getDropSide(event: DragEvent): 'before' | 'after' {
    const currentTarget = event.currentTarget;
    if (!(currentTarget instanceof HTMLElement)) {
        return 'after';
    }

    const bounds = currentTarget.getBoundingClientRect();
    return event.clientY < bounds.top + (bounds.height / 2) ? 'before' : 'after';
}

function handleDragStart(event: DragEvent): void {
    if (isEditingCustomLabel.value) {
        event.preventDefault();
        return;
    }

    suppressNextClick();
    event.dataTransfer?.setData('text/plain', String(props.id));
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
    emit('drag-start');
}

function handleDragOver(event: DragEvent): void {
    if (isEditingCustomLabel.value) {
        return;
    }

    event.preventDefault();
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }
    emit('drag-over', getDropSide(event));
}

function handleDrop(event: DragEvent): void {
    if (isEditingCustomLabel.value) {
        return;
    }

    event.preventDefault();
    suppressNextClick();
    emit('drag-drop', getDropSide(event));
}

function handleDragEnd(): void {
    suppressNextClick();
    emit('drag-end');
}
</script>

<template>
    <ContextMenu>
        <ContextMenuTrigger as-child>
            <div
                v-bind="attrs"
                :draggable="!isEditingCustomLabel"
                :class="[
                    'group relative flex items-center justify-between w-full min-h-10 px-2.5 py-1.5 rounded-md transition-all cursor-grab active:cursor-grabbing select-none',
                    isActive
                        ? 'bg-smart-blue-600/80 text-white'
                        : 'text-twilight-indigo-200 hover:bg-prussian-blue-700/50 hover:text-twilight-indigo-100',
                    isDragging ? 'opacity-60 scale-[0.99]' : '',
                ]"
                :title="customLabel ? `${customLabel} - ${label}` : label"
                role="button"
                tabindex="0"
                @click="handleClick"
                @mousedown="handleMouseDown"
                @dragstart="handleDragStart"
                @dragover="handleDragOver"
                @drop="handleDrop"
                @dragend="handleDragEnd"
                @keydown.enter="handleClick"
                @keydown.space.prevent="handleClick"
            >
                <div
                    v-if="dropIndicator === 'before'"
                    class="pointer-events-none absolute inset-x-2 top-0 h-0.5 rounded-full bg-smart-blue-300"
                />
                <div
                    v-if="dropIndicator === 'after'"
                    class="pointer-events-none absolute inset-x-2 bottom-0 h-0.5 rounded-full bg-smart-blue-300"
                />
                <div class="flex items-center gap-2 flex-1 min-w-0">
                    <Layers class="w-4 h-4 shrink-0" />
                    <div
                        v-show="!isMinimized"
                        class="min-w-0 flex-1 transition-opacity duration-200"
                        :class="!isMinimized ? 'opacity-100' : 'opacity-0'"
                    >
                        <div
                            v-if="isEditingCustomLabel"
                            class="min-w-0 flex flex-col gap-0.5"
                            @click.stop
                            @mousedown.stop
                        >
                            <input
                                ref="customLabelInput"
                                v-model="draftCustomLabel"
                                type="text"
                                maxlength="255"
                                draggable="false"
                                class="h-6 w-full rounded border border-white/20 bg-black/20 px-2 text-xs text-white outline-none placeholder:text-white/50"
                                placeholder="Custom label"
                                data-test="tab-custom-label-input"
                                @keydown.stop="handleCustomLabelKeydown"
                                @keyup.stop
                                @blur="commitCustomLabelEdit"
                            >
                            <span class="truncate text-[10px] text-current/70">
                                {{ label }}
                            </span>
                        </div>
                        <div v-else class="min-w-0">
                            <span class="block truncate text-xs font-medium whitespace-nowrap">
                                {{ customLabel ?? label }}
                            </span>
                            <span v-if="customLabel" class="block truncate text-[10px] text-current/70 whitespace-nowrap">
                                {{ label }}
                            </span>
                        </div>
                    </div>
                    <Loader2
                        v-if="isLoading"
                        :size="12"
                        class="animate-spin shrink-0 transition-opacity duration-200"
                        :class="!isMinimized ? 'opacity-100' : 'opacity-0'"
                        data-test="tab-loading-indicator"
                    />
                    <div
                        v-if="isMasonryLoading"
                        class="flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-smart-blue-500/20 text-smart-blue-300 text-[10px] font-medium shrink-0"
                        :class="!isMinimized ? 'opacity-100' : 'opacity-0'"
                        data-test="tab-masonry-loading-indicator"
                    >
                        <Loader2 :size="10" class="animate-spin" />
                    </div>
                </div>
                <div
                    v-show="!isMinimized"
                    class="flex items-center shrink-0"
                    :class="!isMinimized ? 'opacity-100' : 'opacity-0'"
                >
                    <button
                        type="button"
                        class="flex items-center justify-center h-6 w-6 rounded text-current opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-all hover:bg-black/20 focus:opacity-100 focus:outline-none shrink-0"
                        aria-label="Close tab"
                        @click.stop="handleClose"
                        @dragstart.stop.prevent
                    >
                        <X :size="14" />
                    </button>
                </div>
            </div>
        </ContextMenuTrigger>
        <ContextMenuContent
            class="w-52 bg-prussian-blue-600 border-twilight-indigo-500 text-twilight-indigo-100"
            @close-auto-focus="handleContextMenuCloseAutoFocus"
        >
            <ContextMenuItem
                :disabled="isMinimized"
                class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                data-test="tab-context-rename"
                @select="queueCustomLabelEdit"
            >
                <SquarePen :size="14" />
                <span>{{ customLabel ? 'Edit custom label' : 'Add custom label' }}</span>
            </ContextMenuItem>
            <template v-if="customLabel">
                <ContextMenuSeparator class="bg-twilight-indigo-500" />
                <ContextMenuItem
                    class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                    data-test="tab-context-clear-label"
                    @select="clearCustomLabel"
                >
                    <span>Clear custom label</span>
                </ContextMenuItem>
            </template>
            <ContextMenuSeparator class="bg-twilight-indigo-500" />
            <ContextMenuItem
                class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                data-test="tab-context-duplicate"
                @select="emit('duplicate')"
            >
                <Copy :size="14" />
                <span>Duplicate tab</span>
            </ContextMenuItem>
            <ContextMenuSeparator class="bg-twilight-indigo-500" />
            <ContextMenuItem
                :disabled="!canCloseAbove"
                class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                data-test="tab-context-close-above"
                @select="emit('close-above')"
            >
                <span>Close tabs above</span>
            </ContextMenuItem>
            <ContextMenuItem
                :disabled="!canCloseBelow"
                class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                data-test="tab-context-close-below"
                @select="emit('close-below')"
            >
                <span>Close tabs below</span>
            </ContextMenuItem>
            <ContextMenuItem
                :disabled="!canCloseOthers"
                class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                data-test="tab-context-close-others"
                @select="emit('close-others')"
            >
                <span>Close other tabs</span>
            </ContextMenuItem>
        </ContextMenuContent>
    </ContextMenu>
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
