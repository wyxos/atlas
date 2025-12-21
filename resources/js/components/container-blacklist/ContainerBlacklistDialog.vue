<script setup lang="ts">
import { ref, computed } from 'vue';
import { Loader2, AlertTriangle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useContainerBlacklists } from '@/composables/useContainerBlacklists';
import type { ContainerBlacklistActionType } from '@/types/container-blacklist';

interface Props {
    container: {
        id: number;
        type: string;
        source: string;
        source_id: string;
        referrer?: string | null;
    } | null;
    open: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    'confirm': [containerId: number, actionType: ContainerBlacklistActionType];
    'blacklist-changed': [];
}>();

const { blacklists, fetchBlacklists, deleteBlacklist, isContainerBlacklisted } = useContainerBlacklists();

const actionType = ref<ContainerBlacklistActionType>('ui_countdown');
const isSaving = ref(false);
const isDeleting = ref(false);

const isOpen = computed({
    get: () => props.open,
    set: (value) => emit('update:open', value),
});

// Expose method to reset state (called by parent when opening)
function resetState(): void {
    isSaving.value = false;
    isDeleting.value = false;
    actionType.value = 'ui_countdown';
}

// Expose method to initialize state (called by parent when opening)
async function initializeState(): Promise<void> {
    resetState();
    await fetchBlacklists();
    if (isAlreadyBlacklisted.value && existingActionType.value) {
        actionType.value = existingActionType.value as ContainerBlacklistActionType;
    }
}

defineExpose({
    resetState,
    initializeState,
});

// Check if container is already blacklisted
const isAlreadyBlacklisted = computed(() => {
    if (!props.container) {
        return false;
    }
    return isContainerBlacklisted(props.container.id);
});

const existingBlacklist = computed(() => {
    if (!props.container) {
        return null;
    }
    return blacklists.value.find((b) => b.id === props.container!.id && b.blacklisted_at !== null) || null;
});

const existingActionType = computed(() => {
    return existingBlacklist.value?.action_type || null;
});

const blacklistedAt = computed(() => {
    return existingBlacklist.value?.blacklisted_at || null;
});

async function handleConfirm(): Promise<void> {
    if (!props.container) {
        return;
    }

    isSaving.value = true;
    emit('confirm', props.container.id, actionType.value);
    // Note: isSaving will be reset by parent component after API call completes, but also reset when dialog reopens
}

async function handleWhitelist(): Promise<void> {
    if (!props.container) {
        return;
    }

    isDeleting.value = true;
    try {
        const success = await deleteBlacklist(props.container.id);
        if (success) {
            emit('blacklist-changed');
            isOpen.value = false;
        }
    } finally {
        isDeleting.value = false;
    }
}

function handleCancel(): void {
    isOpen.value = false;
    actionType.value = 'ui_countdown';
    isSaving.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-[500px] bg-prussian-blue-600">
            <DialogHeader>
                <DialogTitle class="text-regal-navy-100">
                    {{ isAlreadyBlacklisted ? 'Container Blacklist Settings' : 'Blacklist Container' }}
                </DialogTitle>
                <DialogDescription class="text-twilight-indigo-300">
                    <span v-if="isAlreadyBlacklisted">
                        This container is currently blacklisted.
                    </span>
                    <span v-else>
                        Choose the type of ban action for this container.
                    </span>
                </DialogDescription>
            </DialogHeader>

            <div v-if="container" class="space-y-4 mt-4">
                <!-- Blacklist Status Alert -->
                <div v-if="isAlreadyBlacklisted" class="p-4 bg-danger-500/20 border border-danger-500/50 rounded-lg">
                    <div class="flex items-start gap-3">
                        <AlertTriangle :size="20" class="text-danger-400 mt-0.5 shrink-0" />
                        <div class="flex-1">
                            <p class="text-sm font-medium text-danger-300 mb-1">Container is Blacklisted</p>
                            <p class="text-xs text-danger-400/80">
                                This container was blacklisted on <strong>{{ blacklistedAt ? new Date(blacklistedAt).toLocaleString() : 'N/A' }}</strong>
                                with action type: <strong>{{ existingActionType || 'N/A' }}</strong>.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Container Details -->
                <div class="space-y-2 p-4 bg-prussian-blue-700/30 rounded-lg border border-twilight-indigo-500/30">
                    <div class="text-sm">
                        <span class="text-twilight-indigo-300">Type:</span>
                        <span class="ml-2 text-regal-navy-100 font-medium">{{ container.type }}</span>
                    </div>
                    <div class="text-sm">
                        <span class="text-twilight-indigo-300">Source:</span>
                        <span class="ml-2 text-regal-navy-100 font-medium">{{ container.source }}</span>
                    </div>
                    <div class="text-sm">
                        <span class="text-twilight-indigo-300">Source ID:</span>
                        <span class="ml-2 text-regal-navy-100 font-medium">{{ container.source_id }}</span>
                    </div>
                    <div v-if="container.referrer" class="text-sm">
                        <span class="text-twilight-indigo-300">Referrer:</span>
                        <a
                            :href="container.referrer"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="ml-2 text-smart-blue-400 hover:underline"
                        >
                            {{ container.referrer }}
                        </a>
                    </div>
                </div>

                <!-- Action Type Selection -->
                <div class="space-y-2">
                    <label class="text-sm font-medium text-regal-navy-100">Ban Type</label>
                    <Select v-model="actionType">
                        <SelectTrigger class="w-full bg-prussian-blue-500 border-twilight-indigo-500 text-regal-navy-100">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="ui_countdown">
                                UI Countdown (5s delay)
                            </SelectItem>
                            <SelectItem value="blacklist">
                                Immediate Blacklist
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-twilight-indigo-400">
                        <span v-if="actionType === 'ui_countdown'">
                            Files in this container will show a 5-second countdown before being auto-disliked.
                        </span>
                        <span v-else>
                            Files in this container will be immediately blacklisted and removed from results.
                        </span>
                    </p>
                </div>
            </div>

            <DialogFooter class="flex items-center justify-between">
                <div>
                    <Button
                        v-if="isAlreadyBlacklisted"
                        variant="destructive"
                        @click="handleWhitelist"
                        :disabled="isDeleting || isSaving"
                    >
                        <Loader2 v-if="isDeleting" :size="14" class="mr-2 animate-spin" />
                        Remove from Blacklist
                    </Button>
                </div>
                <div class="flex gap-2">
                    <Button variant="outline" @click="handleCancel" :disabled="isSaving || isDeleting">
                        Cancel
                    </Button>
                    <Button @click="handleConfirm" :disabled="isSaving || isDeleting || !container">
                        <Loader2 v-if="isSaving" :size="14" class="mr-2 animate-spin" />
                        {{ isAlreadyBlacklisted ? 'Update Blacklist' : 'Confirm Blacklist' }}
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

