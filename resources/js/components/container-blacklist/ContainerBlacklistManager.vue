<script setup lang="ts">
import { ref, nextTick } from 'vue';
import { Ban, Loader2, AlertTriangle, Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import ContainerBlacklistDialog from './ContainerBlacklistDialog.vue';
import { useContainerBlacklists } from '@/composables/useContainerBlacklists';
import type { ContainerBlacklist, ContainerBlacklistActionType } from '@/types/container-blacklist';

interface Props {
    disabled?: boolean;
}

withDefaults(defineProps<Props>(), {
    disabled: false,
});

const emit = defineEmits<{
    'blacklists-changed': [];
}>();

const {
    blacklists,
    isLoading,
    error,
    fetchBlacklists,
    createBlacklist,
    deleteBlacklist,
} = useContainerBlacklists();

// Dialog state
const isDialogOpen = ref(false);
const isBlacklistDialogOpen = ref(false);
const selectedContainer = ref<ContainerBlacklist | null>(null);
const isSaving = ref(false);
const isDeleting = ref(false);

// Container to blacklist (from pill click)
const containerToBlacklist = ref<{
    id: number;
    type: string;
    source: string;
    source_id: string;
    referrer?: string | null;
} | null>(null);

// Ref to blacklist dialog component
const blacklistDialogRef = ref<InstanceType<typeof ContainerBlacklistDialog> | null>(null);

// Open dialog and fetch blacklists
async function openDialog(): Promise<void> {
    isDialogOpen.value = true;
    selectedContainer.value = null;
    containerToBlacklist.value = null;
    await fetchBlacklists();
}

// Open blacklist dialog for a specific container (from pill X button)
async function openBlacklistDialog(container: {
    id: number;
    type: string;
    source: string;
    source_id: string;
    referrer?: string | null;
}): Promise<void> {
    containerToBlacklist.value = container;
    isBlacklistDialogOpen.value = true;
    // Wait for dialog to mount, then reset and initialize state
    await nextTick();
    if (blacklistDialogRef.value) {
        await blacklistDialogRef.value.initializeState();
    }
}

// Handle confirm from blacklist dialog
async function handleConfirmBlacklist(containerId: number, actionType: ContainerBlacklistActionType): Promise<void> {
    isSaving.value = true;

    try {
        const created = await createBlacklist(containerId, actionType);
        if (created) {
            isBlacklistDialogOpen.value = false;
            containerToBlacklist.value = null;
            emit('blacklists-changed');
        }
    } catch (error) {
        // Error is already handled by the composable
        console.error('Failed to create container blacklist:', error);
    } finally {
        isSaving.value = false;
    }
}

// Delete blacklist
async function confirmDeleteBlacklist(container: ContainerBlacklist): Promise<void> {
    if (!confirm('Are you sure you want to remove this container from the blacklist?')) {
        return;
    }

    isDeleting.value = true;

    try {
        const success = await deleteBlacklist(container.id);
        if (success) {
            selectedContainer.value = null;
            emit('blacklists-changed');
        }
    } finally {
        isDeleting.value = false;
    }
}

// Expose method to open blacklist dialog from parent
defineExpose({
    openBlacklistDialog,
});
</script>

<template>
    <div>
        <!-- Trigger Button -->
        <Button
            size="sm"
            variant="ghost"
            class="h-10 w-10"
            data-test="container-blacklist-button"
            title="Container Blacklists"
            :disabled="disabled"
            @click="openDialog"
        >
            <Ban :size="14" />
        </Button>

        <!-- Main Dialog -->
        <Dialog v-model:open="isDialogOpen">
            <DialogContent class="w-[90vw] max-w-[1100px] max-h-[85vh] bg-prussian-blue-600 p-0 overflow-hidden">
                <DialogHeader class="px-6 pt-6 pb-4 border-b border-twilight-indigo-500/30">
                    <div class="flex items-center gap-3">
                        <Ban :size="20" class="text-smart-blue-400" />
                        <div>
                            <DialogTitle class="text-regal-navy-100">Container Blacklists</DialogTitle>
                            <DialogDescription class="text-twilight-indigo-300">
                                Manage blacklisted containers and their ban actions.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div class="flex h-[65vh]">
                    <!-- Left Panel: Blacklist List -->
                    <div class="w-[320px] flex flex-col bg-prussian-blue-700/30 border-r border-twilight-indigo-500/30">
                        <!-- List Header -->
                        <div class="flex items-center justify-between p-3 border-b border-twilight-indigo-500/30">
                            <span class="text-xs font-medium text-twilight-indigo-300 uppercase tracking-wider">
                                Blacklisted ({{ blacklists.length }})
                            </span>
                        </div>

                        <!-- List Content -->
                        <div class="flex-1 overflow-y-auto">
                            <!-- Loading -->
                            <div v-if="isLoading && blacklists.length === 0" class="flex items-center justify-center py-8">
                                <Loader2 :size="24" class="animate-spin text-twilight-indigo-300" />
                            </div>

                            <!-- Error -->
                            <div v-else-if="error" class="flex flex-col items-center justify-center py-8 px-4 text-center">
                                <AlertTriangle :size="24" class="text-danger-400 mb-2" />
                                <p class="text-xs text-danger-300">{{ error }}</p>
                                <Button variant="outline" size="sm" class="mt-2 h-8" @click="fetchBlacklists">
                                    Retry
                                </Button>
                            </div>

                            <!-- Empty -->
                            <div v-else-if="blacklists.length === 0" class="py-8 px-4 text-center">
                                <p class="text-sm text-twilight-indigo-400">No blacklisted containers yet.</p>
                            </div>

                            <!-- Blacklists List -->
                            <ul v-else>
                                <li
                                    v-for="blacklist in blacklists"
                                    :key="blacklist.id"
                                    @click="selectedContainer = blacklist"
                                    class="cursor-pointer border-b border-twilight-indigo-500/20 p-3 hover:bg-prussian-blue-500/50 transition-colors"
                                    :class="selectedContainer?.id === blacklist.id ? 'bg-prussian-blue-500/70' : ''"
                                >
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-sm font-medium text-regal-navy-100 truncate">
                                            {{ blacklist.type }}
                                        </span>
                                        <div class="flex items-center gap-1.5 shrink-0 ml-2">
                                            <span
                                                class="px-1.5 py-0.5 text-[10px] font-medium rounded"
                                                :class="{
                                                    'bg-emerald-500/20 text-emerald-400': blacklist.action_type === 'ui_countdown',
                                                    'bg-amber-500/20 text-amber-400': blacklist.action_type === 'auto_dislike',
                                                    'bg-danger-500/20 text-danger-400': blacklist.action_type === 'blacklist',
                                                }"
                                            >
                                                {{ blacklist.action_type }}
                                            </span>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-twilight-indigo-400 truncate">
                                        {{ blacklist.source }} - {{ blacklist.source_id }}
                                    </p>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Right Panel: Container Details -->
                    <div class="flex-1 p-6 overflow-y-auto">
                        <template v-if="selectedContainer">
                            <div class="space-y-5">
                                <!-- Container Details -->
                                <div class="space-y-2">
                                    <label class="text-sm font-medium text-regal-navy-100">Container Details</label>
                                    <div class="p-4 bg-prussian-blue-700/30 rounded-lg border border-twilight-indigo-500/30 space-y-2">
                                        <div class="text-sm">
                                            <span class="text-twilight-indigo-300">Type:</span>
                                            <span class="ml-2 text-regal-navy-100 font-medium">{{ selectedContainer.type }}</span>
                                        </div>
                                        <div class="text-sm">
                                            <span class="text-twilight-indigo-300">Source:</span>
                                            <span class="ml-2 text-regal-navy-100 font-medium">{{ selectedContainer.source }}</span>
                                        </div>
                                        <div class="text-sm">
                                            <span class="text-twilight-indigo-300">Source ID:</span>
                                            <span class="ml-2 text-regal-navy-100 font-medium">{{ selectedContainer.source_id }}</span>
                                        </div>
                                        <div v-if="selectedContainer.referrer" class="text-sm">
                                            <span class="text-twilight-indigo-300">Referrer:</span>
                                            <a
                                                :href="selectedContainer.referrer"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="ml-2 text-smart-blue-400 hover:underline"
                                            >
                                                {{ selectedContainer.referrer }}
                                            </a>
                                        </div>
                                        <div class="text-sm">
                                            <span class="text-twilight-indigo-300">Action Type:</span>
                                            <span class="ml-2 text-regal-navy-100 font-medium">{{ selectedContainer.action_type }}</span>
                                        </div>
                                        <div v-if="selectedContainer.blacklisted_at" class="text-sm">
                                            <span class="text-twilight-indigo-300">Blacklisted At:</span>
                                            <span class="ml-2 text-regal-navy-100 font-medium">{{ new Date(selectedContainer.blacklisted_at).toLocaleString() }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center gap-3 pt-4 border-t border-twilight-indigo-500/30">
                                    <Button
                                        variant="destructive"
                                        @click="confirmDeleteBlacklist(selectedContainer)"
                                        :disabled="isDeleting"
                                        class="h-10"
                                    >
                                        <Trash2 v-if="!isDeleting" :size="14" class="mr-2" />
                                        <Loader2 v-if="isDeleting" :size="14" class="mr-2 animate-spin" />
                                        Remove from Blacklist
                                    </Button>
                                </div>
                            </div>
                        </template>
                        <template v-else>
                            <div class="flex flex-col items-center justify-center h-full text-center">
                                <Ban :size="48" class="text-twilight-indigo-400 mb-3 opacity-50" />
                                <p class="text-sm text-twilight-indigo-300">
                                    Select a blacklisted container from the list<br />to view details.
                                </p>
                            </div>
                        </template>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Blacklist Dialog (for pill X button) -->
        <ContainerBlacklistDialog
            ref="blacklistDialogRef"
            :container="containerToBlacklist"
            :open="isBlacklistDialogOpen"
            @update:open="(val) => { isBlacklistDialogOpen = val; if (!val) containerToBlacklist = null; }"
            @confirm="handleConfirmBlacklist"
            @blacklist-changed="() => { emit('blacklists-changed'); fetchBlacklists(); }"
        />
    </div>
</template>

