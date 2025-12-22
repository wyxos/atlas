<script setup lang="ts">
import { computed } from 'vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Shield } from 'lucide-vue-next';
import FileReactions from '@/components/FileReactions.vue';
import { queueReaction } from '@/utils/reactionQueue';
import type { ReactionType } from '@/types/reaction';

interface ModeratedFile {
    id: number;
    action_type: string;
    thumbnail?: string;
}

interface Props {
    open: boolean;
    files: ModeratedFile[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

// Internal state for v-model
const isModalOpen = computed({
    get: () => props.open,
    set: (value: boolean) => {
        emit('update:open', value);
    },
});

function handleClose(): void {
    isModalOpen.value = false;
}

function handleFileReaction(fileId: number, type: ReactionType): void {
    // Queue reaction (same as masonry interaction)
    // These files are already moderated out, so we don't need to remove from masonry
    const file = props.files.find(f => f.id === fileId);
    const thumbnail = file?.thumbnail;
    queueReaction(fileId, type, thumbnail);
}

// Group files by action type
const filesByAction = computed(() => {
    const grouped: Record<string, ModeratedFile[]> = {};
    for (const file of props.files) {
        const action = file.action_type || 'dislike';
        if (!grouped[action]) {
            grouped[action] = [];
        }
        grouped[action].push(file);
    }
    return grouped;
});

// Format action type for display
function formatActionType(actionType: string): string {
    const labels: Record<string, string> = {
        dislike: 'Disliked',
        blacklist: 'Blacklisted',
    };
    return labels[actionType] || actionType.replace('_', ' ').replace(/\b\w/g, (l) => l.toUpperCase());
}
</script>

<template>
    <Dialog v-model:open="isModalOpen">
        <DialogContent class="sm:max-w-[800px] bg-prussian-blue-600 border-danger-500/30 max-h-[80vh] flex flex-col">
            <DialogHeader>
                <DialogTitle class="text-danger-400 flex items-center gap-2">
                    <Shield class="size-5" />
                    Moderation Review
                </DialogTitle>
                <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                    {{ files.length }} file{{ files.length !== 1 ? 's' : '' }} were moderated during the last page load.
                </DialogDescription>
            </DialogHeader>

            <div class="flex-1 overflow-y-auto mt-4">
                <div v-for="(fileGroup, actionType) in filesByAction" :key="actionType" class="mb-6">
                    <h3 class="text-sm font-semibold text-twilight-indigo-200 mb-3">
                        {{ formatActionType(actionType) }} ({{ fileGroup.length }})
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <div v-for="file in fileGroup" :key="file.id"
                            class="relative aspect-square rounded-lg overflow-hidden border-2 border-danger-500/50 bg-prussian-blue-500">
                            <img v-if="file.thumbnail" :src="file.thumbnail" :alt="`File ${file.id}`"
                                class="w-full h-full object-cover" />
                            <div v-else class="w-full h-full flex items-center justify-center bg-prussian-blue-500/50">
                                <span class="text-xs text-twilight-indigo-300">#{{ file.id }}</span>
                            </div>
                            <div
                                class="absolute bottom-0 left-0 right-0 bg-black/60 text-white text-xs px-1 py-0.5 text-center truncate">
                                #{{ file.id }}
                            </div>
                            <!-- File Reactions Component -->
                            <div class="absolute bottom-8 left-0 right-0 flex justify-center px-1">
                                <FileReactions :file-id="file.id" mode="reaction-only" variant="small"
                                    @reaction="(type) => handleFileReaction(file.id, type)" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-4 pt-4 border-t border-twilight-indigo-500/30">
                <Button variant="outline" @click="handleClose">
                    Close
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
