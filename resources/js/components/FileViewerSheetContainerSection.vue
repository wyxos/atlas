<script setup lang="ts">
import { Ban, ShieldCheck } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import type { FileContainer } from '@/types/file';

defineProps<{
    containers: FileContainer[];
    canManageContainerBlacklist: (container: FileContainer) => boolean;
}>();

const emit = defineEmits<{
    manage: [container: FileContainer];
}>();
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="font-semibold text-white">Containers</div>
        <div class="flex flex-col gap-3">
            <div
                v-for="container in containers"
                :key="container.id"
                class="rounded-lg border border-twilight-indigo-500/50 bg-prussian-blue-900/50 p-3"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-semibold text-white">
                            #{{ container.id }} {{ container.type || 'Container' }}
                        </div>
                        <div class="wrap-break-word text-xs text-twilight-indigo-100">
                            {{ container.source }}
                            <template v-if="container.source_id">
                                · {{ container.source_id }}
                            </template>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <div class="text-[10px] uppercase tracking-wide text-twilight-indigo-100">
                            {{ container.blacklisted ? 'blacklisted' : 'not blacklisted' }}
                        </div>
                        <Button
                            v-if="canManageContainerBlacklist(container)"
                            size="sm"
                            :variant="container.blacklisted ? 'outline' : 'destructive'"
                            :color="container.blacklisted ? 'default' : 'danger'"
                            :data-test="`file-container-blacklist-action-${container.id}`"
                            @click="emit('manage', container)"
                        >
                            <ShieldCheck v-if="container.blacklisted" data-icon="inline-start" />
                            <Ban v-else data-icon="inline-start" />
                            {{ container.blacklisted ? 'Remove from blacklist' : 'Add to blacklist' }}
                        </Button>
                    </div>
                </div>
                <div v-if="container.referrer" class="mt-3">
                    <div class="font-semibold text-white mb-1">Referrer</div>
                    <a
                        :href="container.referrer"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="wrap-break-word text-smart-blue-400 hover:text-smart-blue-300"
                    >
                        {{ container.referrer }}
                    </a>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                    <div class="rounded bg-prussian-blue-700/60 p-2">
                        <div class="font-semibold text-white">Unreacted</div>
                        <div>{{ container.file_stats.unreacted }}</div>
                    </div>
                    <div class="rounded bg-prussian-blue-700/60 p-2">
                        <div class="font-semibold text-white">Blacklisted</div>
                        <div>{{ container.file_stats.blacklisted }}</div>
                    </div>
                    <div class="rounded bg-prussian-blue-700/60 p-2">
                        <div class="font-semibold text-white">Positive</div>
                        <div>{{ container.file_stats.positive }}</div>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <div class="font-semibold text-white mb-1">Action</div>
                        <div class="uppercase tracking-wide text-twilight-indigo-100">
                            {{ container.action_type || 'none' }}
                        </div>
                    </div>
                    <div v-if="container.blacklisted_at">
                        <div class="font-semibold text-white mb-1">Blacklisted At</div>
                        <div>{{ new Date(container.blacklisted_at).toLocaleString() }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
