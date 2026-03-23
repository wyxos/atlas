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
import type {
    ContainerBlacklistActionType,
    ContainerBlacklistStatus,
} from '@/types/container-blacklist';

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

const { checkBlacklist, deleteBlacklist } = useContainerBlacklists();

const actionType = ref<ContainerBlacklistActionType>('blacklist');
const isSaving = ref(false);
const isDeleting = ref(false);
const isCheckingStatus = ref(false);
const status = ref<ContainerBlacklistStatus | null>(null);

const isOpen = computed({
    get: () => props.open,
    set: (value) => emit('update:open', value),
});

// Expose method to reset state (called by parent when opening)
function resetState(): void {
    isSaving.value = false;
    isDeleting.value = false;
    isCheckingStatus.value = false;
    status.value = null;
    actionType.value = 'blacklist';
}

// Expose method to initialize state (called by parent when opening)
async function initializeState(): Promise<void> {
    resetState();
    if (!props.container) {
        return;
    }

    isCheckingStatus.value = true;

    try {
        status.value = await checkBlacklist(props.container.id);

        if (status.value?.blacklisted && status.value.action_type) {
            actionType.value = status.value.action_type;
        }
    } finally {
        isCheckingStatus.value = false;
    }
}

defineExpose({
    resetState,
    initializeState,
});

// Check if container is already blacklisted
const isAlreadyBlacklisted = computed(() => {
    return status.value?.blacklisted ?? false;
});

const existingActionType = computed(() => {
    return status.value?.action_type || null;
});

const blacklistedAt = computed(() => {
    return status.value?.blacklisted_at || null;
});

const fileStats = computed(() => {
    return status.value?.file_stats ?? null;
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
    actionType.value = 'blacklist';
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

                <div class="space-y-2">
                    <label class="text-sm font-medium text-regal-navy-100">Container Signal</label>
                    <div
                        v-if="isCheckingStatus"
                        class="flex items-center gap-2 rounded-lg border border-twilight-indigo-500/30 bg-prussian-blue-700/30 p-3 text-sm text-twilight-indigo-300"
                    >
                        <Loader2 :size="16" class="animate-spin" />
                        Loading container stats...
                    </div>
                    <div v-else-if="fileStats" class="grid gap-2 sm:grid-cols-4">
                        <div
                            data-test="container-stat-unreacted"
                            class="rounded-lg border border-twilight-indigo-500/40 bg-twilight-indigo-500/10 p-3"
                        >
                            <p class="text-[11px] font-medium uppercase tracking-wider text-twilight-indigo-300">Unreacted</p>
                            <p class="mt-1 text-xl font-semibold text-regal-navy-100">{{ fileStats.unreacted }}</p>
                        </div>
                        <div
                            data-test="container-stat-blacklisted"
                            class="rounded-lg border border-danger-500/40 bg-danger-500/10 p-3"
                        >
                            <p class="text-[11px] font-medium uppercase tracking-wider text-danger-300">Blacklisted (Any)</p>
                            <p class="mt-1 text-xl font-semibold text-danger-200">{{ fileStats.blacklisted }}</p>
                        </div>
                        <div
                            data-test="container-stat-disliked"
                            class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3"
                        >
                            <p class="text-[11px] font-medium uppercase tracking-wider text-amber-300">Disliked</p>
                            <p class="mt-1 text-xl font-semibold text-amber-200">{{ fileStats.disliked }}</p>
                        </div>
                        <div
                            data-test="container-stat-positive"
                            class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3"
                        >
                            <p class="text-[11px] font-medium uppercase tracking-wider text-emerald-300">Positive</p>
                            <p class="mt-1 text-xl font-semibold text-emerald-200">{{ fileStats.positive }}</p>
                        </div>
                    </div>
                    <p v-else class="rounded-lg border border-twilight-indigo-500/30 bg-prussian-blue-700/30 p-3 text-sm text-twilight-indigo-300">
                        Container stats are unavailable right now.
                    </p>
                </div>

                <!-- Action Type Selection -->
                <div class="space-y-2">
                    <label class="text-sm font-medium text-regal-navy-100">Ban Type</label>
                    <Select v-model="actionType">
                        <SelectTrigger class="w-full bg-prussian-blue-500 border-twilight-indigo-500 text-regal-navy-100">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="dislike">
                                Dislike (5s countdown)
                            </SelectItem>
                            <SelectItem value="blacklist">
                                Immediate Blacklist
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-twilight-indigo-400">
                        <span v-if="actionType === 'dislike'">
                            Files in this container will show a 5-second countdown before being disliked.
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
                    <Button variant="destructive" @click="handleConfirm" :disabled="isSaving || isDeleting || !container">
                        <Loader2 v-if="isSaving" :size="14" class="mr-2 animate-spin" />
                        Confirm
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
