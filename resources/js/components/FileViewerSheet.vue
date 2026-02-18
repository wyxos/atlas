<script setup lang="ts">
import { Copy, Loader2, PanelRightClose } from 'lucide-vue-next';
import { copyToClipboard } from '@/utils/clipboard';

interface Props {
    isOpen: boolean;
    fileId: number | null;
    fileData: import('@/types/file').File | null;
    isLoading: boolean;
}

defineProps<Props>();

function normalizePathForOs(path: string): string {
    const platform = typeof navigator !== 'undefined' ? navigator.platform : '';
    const isWindows = /win/i.test(platform);

    return isWindows
        ? path.replace(/\//g, '\\')
        : path.replace(/\\/g, '/');
}

async function handleCopyPath(absolutePath: string | null): Promise<void> {
    if (!absolutePath) {
        return;
    }

    try {
        await copyToClipboard(normalizePathForOs(absolutePath), 'Path', { showToast: false });
    } catch {
        // Ignore clipboard errors; there is no fallback UI in this sheet.
    }
}

async function handleCopyText(text: string | null, label: string): Promise<void> {
    if (!text) {
        return;
    }

    try {
        await copyToClipboard(text, label, { showToast: false });
    } catch {
        // Ignore clipboard errors; there is no fallback UI in this sheet.
    }
}

const emit = defineEmits<{
    close: [];
}>();
</script>

<template>
    <div
        class="flex h-full min-h-0 flex-col bg-prussian-blue-800 border-l-2 border-twilight-indigo-500 shrink-0 transition-all duration-300 ease-in-out overflow-hidden pointer-events-auto"
        :class="isOpen ? 'w-80 max-w-80' : 'w-0 max-w-0'"
    >
        <div class="flex h-full min-h-0 w-80 min-w-80 max-w-80 flex-col">
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
                    aria-label="Hide details panel"
                >
                    <PanelRightClose :size="20" />
                </button>
            </div>
            <div
                class="flex-1 min-h-0 overflow-y-auto overscroll-y-contain p-4 min-w-0"
                :class="isOpen ? '' : 'opacity-0 pointer-events-none'"
            >
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
                    <div v-if="fileData.width !== null && fileData.height !== null">
                        <div class="font-semibold text-white mb-1">Resolution</div>
                        <div>{{ fileData.width }} x {{ fileData.height }}</div>
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
                        <div class="mt-1 flex min-w-0 items-center gap-2">
                            <a
                                :href="fileData.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="min-w-0 flex-1 truncate text-smart-blue-400 hover:text-smart-blue-300"
                                :title="fileData.url"
                            >
                                {{ fileData.url }}
                            </a>
                            <button
                                type="button"
                                class="shrink-0 rounded p-1 text-white/80 hover:bg-prussian-blue-700 hover:text-white disabled:opacity-50"
                                aria-label="Copy URL"
                                :disabled="!fileData.url"
                                @click="handleCopyText(fileData.url, 'URL')"
                            >
                                <Copy :size="16" />
                            </button>
                        </div>
                    </div>
                    <div v-if="fileData.disk_url">
                        <div class="font-semibold text-white mb-1">Disk URL</div>
                        <div class="mt-1 flex min-w-0 items-center gap-2">
                            <a
                                :href="fileData.disk_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="min-w-0 flex-1 truncate text-smart-blue-400 hover:text-smart-blue-300"
                                :title="fileData.disk_url"
                            >
                                {{ fileData.disk_url }}
                            </a>
                            <button
                                type="button"
                                class="shrink-0 rounded p-1 text-white/80 hover:bg-prussian-blue-700 hover:text-white disabled:opacity-50"
                                aria-label="Copy disk URL"
                                :disabled="!fileData.disk_url"
                                @click="handleCopyText(fileData.disk_url, 'Disk URL')"
                            >
                                <Copy :size="16" />
                            </button>
                        </div>
                    </div>
                    <div v-if="fileData.path">
                        <div class="font-semibold text-white mb-1">Path</div>
                        <div class="mt-1 flex min-w-0 items-center gap-2">
                            <span class="min-w-0 flex-1 truncate text-smart-blue-400" :title="fileData.path">
                                {{ fileData.path }}
                            </span>
                            <button
                                type="button"
                                class="shrink-0 rounded p-1 text-white/80 hover:bg-prussian-blue-700 hover:text-white disabled:opacity-50"
                                title="Copy absolute path"
                                aria-label="Copy path"
                                data-test="file-path"
                                :disabled="!fileData.absolute_path"
                                @click="handleCopyPath(fileData.absolute_path)"
                            >
                                <Copy :size="16" />
                            </button>
                        </div>
                    </div>
                    <div v-if="fileData.preview_path">
                        <div class="font-semibold text-white mb-1">Preview Path</div>
                        <div class="mt-1 flex min-w-0 items-center gap-2">
                            <span class="min-w-0 flex-1 truncate text-smart-blue-400" :title="fileData.preview_path">
                                {{ fileData.preview_path }}
                            </span>
                            <button
                                type="button"
                                class="shrink-0 rounded p-1 text-white/80 hover:bg-prussian-blue-700 hover:text-white disabled:opacity-50"
                                title="Copy absolute preview path"
                                aria-label="Copy preview path"
                                data-test="preview-path"
                                :disabled="!fileData.absolute_preview_path"
                                @click="handleCopyPath(fileData.absolute_preview_path)"
                            >
                                <Copy :size="16" />
                            </button>
                        </div>
                    </div>
                    <div v-if="fileData.referrer_url">
                        <div class="font-semibold text-white mb-1">Referrer</div>
                        <div class="mt-1 flex min-w-0 items-center gap-2">
                            <a
                                :href="fileData.referrer_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="min-w-0 flex-1 truncate text-smart-blue-400 hover:text-smart-blue-300"
                                :title="fileData.referrer_url"
                            >
                                {{ fileData.referrer_url }}
                            </a>
                            <button
                                type="button"
                                class="shrink-0 rounded p-1 text-white/80 hover:bg-prussian-blue-700 hover:text-white disabled:opacity-50"
                                aria-label="Copy referrer URL"
                                :disabled="!fileData.referrer_url"
                                @click="handleCopyText(fileData.referrer_url, 'Referrer')"
                            >
                                <Copy :size="16" />
                            </button>
                        </div>
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
                    <div v-if="fileData.auto_disliked || fileData.auto_dislike_rule" class="space-y-2">
                        <div class="font-semibold text-white mb-1">Auto Dislike</div>
                        <div class="uppercase tracking-wide text-xs text-twilight-indigo-100">
                            {{ fileData.auto_disliked ? 'applied' : 'flagged' }}
                        </div>
                        <div v-if="fileData.auto_dislike_rule">
                            <div class="font-semibold text-white mb-1">Moderation Rule (Flagged)</div>
                            <div class="wrap-break-word">
                                #{{ fileData.auto_dislike_rule.id }} {{ fileData.auto_dislike_rule.name }}
                            </div>
                        </div>
                    </div>
                    <div v-if="fileData.blacklisted_at" class="space-y-2">
                        <div class="font-semibold text-white mb-1">Blacklisted</div>
                        <div>{{ new Date(fileData.blacklisted_at).toLocaleString() }}</div>
                        <div>
                            <div class="font-semibold text-white mb-1">Blacklist Type</div>
                            <div class="uppercase tracking-wide text-xs text-twilight-indigo-100">
                                {{ fileData.blacklist_type || (fileData.blacklist_reason ? 'manual' : 'auto') }}
                            </div>
                        </div>
                        <div v-if="fileData.blacklist_reason">
                            <div class="font-semibold text-white mb-1">Blacklist Reason</div>
                            <div class="wrap-break-word">{{ fileData.blacklist_reason }}</div>
                        </div>
                        <div v-else-if="fileData.blacklist_rule">
                            <div class="font-semibold text-white mb-1">Moderation Rule (Matched)</div>
                            <div class="wrap-break-word">#{{ fileData.blacklist_rule.id }} {{ fileData.blacklist_rule.name }}</div>
                        </div>
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





