<script setup lang="ts">
import { X, Loader2 } from 'lucide-vue-next';

interface Props {
    isOpen: boolean;
    fileId: number | null;
    fileData: import('@/types/file').File | null;
    isLoading: boolean;
}

defineProps<Props>();

const emit = defineEmits<{
    close: [];
}>();
</script>

<template>
    <div
        class="flex flex-col bg-prussian-blue-800 border-l-2 border-twilight-indigo-500 shrink-0 transition-all duration-300 ease-in-out overflow-hidden"
        :class="isOpen ? 'w-80 max-w-80' : 'w-0 max-w-0'"
    >
        <div class="flex w-80 min-w-80 max-w-80 flex-col">
            <div
                class="flex items-center justify-between p-4 border-b border-twilight-indigo-500 shrink-0 whitespace-nowrap"
                :class="isOpen ? '' : 'opacity-0 pointer-events-none'"
            >
                <h2 class="text-lg font-semibold text-white">
                    # {{ fileId || '' }}
                </h2>
                <button
                    @click="emit('close')"
                    class="p-2 rounded-lg hover:bg-prussian-blue-700 text-white transition-colors"
                    aria-label="Close sheet"
                >
                    <X :size="20" />
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-4 min-w-0" :class="isOpen ? '' : 'opacity-0 pointer-events-none'">
                <div v-if="isLoading" class="flex items-center justify-center py-8">
                    <Loader2 :size="24" class="animate-spin text-smart-blue-500" />
                </div>
                <div v-else-if="fileData" class="space-y-4 text-sm text-twilight-indigo-200">
                    <div>
                        <div class="font-semibold text-white mb-1">Source</div>
                        <div>{{ fileData.source || 'N/A' }}</div>
                    </div>
                    <div v-if="fileData.filename">
                        <div class="font-semibold text-white mb-1">Filename</div>
                        <div class="wrap-break-word">{{ fileData.filename }}</div>
                    </div>
                    <div v-if="fileData.mime_type">
                        <div class="font-semibold text-white mb-1">MIME Type</div>
                        <div>{{ fileData.mime_type }}</div>
                    </div>
                    <div v-if="fileData.size">
                        <div class="font-semibold text-white mb-1">Size</div>
                        <div>{{ (fileData.size / 1024 / 1024).toFixed(2) }} MB</div>
                    </div>
                    <div v-if="fileData.downloaded !== undefined">
                        <div class="font-semibold text-white mb-1">Downloaded</div>
                        <div>{{ fileData.downloaded ? 'Yes' : 'No' }}</div>
                    </div>
                    <div v-if="fileData.downloaded_at">
                        <div class="font-semibold text-white mb-1">Downloaded At</div>
                        <div>{{ new Date(fileData.downloaded_at).toLocaleString() }}</div>
                    </div>
                    <div v-if="fileData.source_id">
                        <div class="font-semibold text-white mb-1">Source ID</div>
                        <div class="wrap-break-word">{{ fileData.source_id }}</div>
                    </div>
                    <div v-if="fileData.title">
                        <div class="font-semibold text-white mb-1">Title</div>
                        <div class="wrap-break-word">{{ fileData.title }}</div>
                    </div>
                    <div v-if="fileData.description">
                        <div class="font-semibold text-white mb-1">Description</div>
                        <div class="wrap-break-word">{{ fileData.description }}</div>
                    </div>
                    <div v-if="fileData.url">
                        <div class="font-semibold text-white mb-1">URL</div>
                        <a :href="fileData.url" target="_blank" rel="noopener noreferrer"
                            class="text-smart-blue-400 hover:text-smart-blue-300 break-all">
                            {{ fileData.url }}
                        </a>
                    </div>
                    <div v-if="fileData.disk_url">
                        <div class="font-semibold text-white mb-1">Disk URL</div>
                        <a :href="fileData.disk_url" target="_blank" rel="noopener noreferrer"
                            class="text-smart-blue-400 hover:text-smart-blue-300 break-all">
                            {{ fileData.disk_url }}
                        </a>
                    </div>
                    <div v-if="fileData.referrer_url">
                        <div class="font-semibold text-white mb-1">Referrer</div>
                        <a :href="fileData.referrer_url" target="_blank" rel="noopener noreferrer"
                            class="text-smart-blue-400 hover:text-smart-blue-300 break-all">
                            {{ fileData.referrer_url }}
                        </a>
                    </div>
                    <div v-if="fileData.tags && Array.isArray(fileData.tags) && fileData.tags.length > 0">
                        <div class="font-semibold text-white mb-1">Tags</div>
                        <div class="flex flex-wrap gap-2">
                            <span v-for="tag in fileData.tags" :key="tag"
                                class="px-2 py-1 bg-smart-blue-500/20 rounded text-xs">
                                {{ tag }}
                            </span>
                        </div>
                    </div>
                    <div v-if="fileData.previewed_count !== undefined">
                        <div class="font-semibold text-white mb-1">Previewed</div>
                        <div>{{ fileData.previewed_count }} times</div>
                    </div>
                    <div v-if="fileData.seen_count !== undefined">
                        <div class="font-semibold text-white mb-1">Seen</div>
                        <div>{{ fileData.seen_count }} times</div>
                    </div>
                    <div v-if="fileData.created_at">
                        <div class="font-semibold text-white mb-1">Created</div>
                        <div>{{ new Date(fileData.created_at).toLocaleString() }}</div>
                    </div>
                </div>
                <div v-else class="text-twilight-indigo-400 text-center py-8">
                    No file data available
                </div>
            </div>
        </div>
    </div>
</template>





