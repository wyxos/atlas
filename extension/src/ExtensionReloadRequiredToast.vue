<script setup lang="ts">
import { RefreshCcw, TriangleAlert } from 'lucide-vue-next';
import { ToastDescription, ToastProvider, ToastRoot, ToastTitle, ToastViewport } from 'reka-ui';

interface Props {
    open: boolean;
}

defineProps<Props>();

const emit = defineEmits<{
    reload: [];
}>();

function emitReload(): void {
    emit('reload');
}
</script>

<template>
    <ToastProvider :duration="2147483647" :disable-swipe="true" label="Extension status">
        <ToastRoot
            :open="open"
            type="foreground"
            data-slot="extension-reload-toast"
            class="atlas-reload-toast"
            @escape-key-down.prevent
        >
            <div class="atlas-reload-toast__content">
                <TriangleAlert class="atlas-reload-toast__icon" />
                <div class="atlas-reload-toast__text">
                    <ToastTitle class="atlas-reload-toast__title">
                        Extension reload required
                    </ToastTitle>
                    <ToastDescription class="atlas-reload-toast__description">
                        Atlas has an update ready. Reload the extension to apply it.
                    </ToastDescription>
                </div>
            </div>

            <div class="atlas-reload-toast__actions">
                <button
                    type="button"
                    class="atlas-reload-toast__action"
                    @click="emitReload"
                >
                    <RefreshCcw class="atlas-reload-toast__action-icon" />
                    Reload Extension
                </button>
            </div>
        </ToastRoot>

        <ToastViewport
            class="atlas-reload-toast__viewport"
        />
    </ToastProvider>
</template>

<style scoped>
.atlas-reload-toast {
    pointer-events: auto;
    border: 1px solid rgba(251, 191, 36, 0.7);
    border-radius: 10px;
    background: rgba(2, 16, 36, 0.96);
    box-shadow: 0 14px 36px rgba(0, 0, 0, 0.48);
    color: #e2e8f0;
    padding: 12px 14px;
}

.atlas-reload-toast__content {
    align-items: flex-start;
    display: flex;
    gap: 10px;
}

.atlas-reload-toast__icon {
    color: #fbbf24;
    flex-shrink: 0;
    height: 16px;
    margin-top: 2px;
    width: 16px;
}

.atlas-reload-toast__text {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.atlas-reload-toast__title {
    color: #f8fafc;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.3;
}

.atlas-reload-toast__description {
    color: #cbd5e1;
    font-size: 12px;
    line-height: 1.35;
}

.atlas-reload-toast__actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 10px;
}

.atlas-reload-toast__action {
    align-items: center;
    background: rgba(245, 158, 11, 0.12);
    border: 1px solid rgba(251, 191, 36, 0.75);
    border-radius: 8px;
    color: #fde68a;
    cursor: pointer;
    display: inline-flex;
    font-size: 12px;
    font-weight: 600;
    gap: 6px;
    height: 32px;
    padding: 0 10px;
    transition: background-color 0.16s ease, color 0.16s ease, border-color 0.16s ease;
}

.atlas-reload-toast__action:hover {
    background: rgba(245, 158, 11, 0.26);
    border-color: rgba(252, 211, 77, 0.95);
    color: #fffbeb;
}

.atlas-reload-toast__action-icon {
    height: 14px;
    width: 14px;
}

.atlas-reload-toast__viewport {
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-width: 360px;
    outline: none;
    pointer-events: none;
    position: fixed;
    right: 16px;
    top: 16px;
    width: min(92vw, 360px);
    z-index: 2147483647;
}
</style>
