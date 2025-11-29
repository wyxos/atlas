<script setup lang="ts">
import { Download, FileText, Copy, ExternalLink } from 'lucide-vue-next';
import Button from './ui/Button.vue';
import { toast } from './ui/sonner';
import { formatDate } from '../utils/date';

interface File {
    id: number;
    source: string;
    source_id: string | null;
    filename: string;
    ext: string | null;
    size: number | null;
    mime_type: string | null;
    hash: string | null;
    title: string | null;
    description: string | null;
    url: string | null;
    referrer_url: string | null;
    path: string | null;
    absolute_path: string | null;
    thumbnail_url: string | null;
    thumbnail_path: string | null;
    tags: string[] | null;
    parent_id: number | null;
    chapter: string | null;
    previewed_at: string | null;
    previewed_count: number;
    seen_at: string | null;
    seen_count: number;
    blacklisted_at: string | null;
    blacklist_reason: string | null;
    downloaded: boolean;
    downloaded_at: string | null;
    download_progress: number;
    not_found: boolean;
    listing_metadata: Record<string, unknown> | null;
    detail_metadata: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    file: File;
}

const props = defineProps<Props>();

function formatFileSize(bytes: number | null): string {
    if (bytes === null || bytes === 0) {
        return '0 B';
    }

    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`;
}

function getMimeTypeCategory(mimeType: string | null): string {
    if (!mimeType) {
        return 'unknown';
    }
    if (mimeType.startsWith('image/')) {
        return 'image';
    }
    if (mimeType.startsWith('video/')) {
        return 'video';
    }
    if (mimeType.startsWith('audio/')) {
        return 'audio';
    }
    return 'other';
}

async function copyToClipboard(text: string, label: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`${label} copied to clipboard`, {
            description: text,
        });
    } catch (err) {
        toast.error('Failed to copy to clipboard', {
            description: 'Please try again or copy manually',
        });
        console.error('Error copying to clipboard:', err);
    }
}

function openUrl(url: string): void {
    window.open(url, '_blank', 'noopener,noreferrer');
}
</script>

<template>
    <div class="space-y-6">
        <!-- Basic Information -->
        <div>
            <h5 class="text-lg font-semibold text-regal-navy-100 mb-4">Basic Information</h5>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Filename</label>
                    <div class="flex items-center gap-2">
                        <FileText :size="16" class="text-smart-blue-400" />
                        <span class="text-regal-navy-100 font-medium">{{ file.filename }}</span>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="() => copyToClipboard(file.filename, 'Filename')"
                            class="p-1 h-auto"
                        >
                            <Copy :size="12" class="text-smart-blue-400" />
                        </Button>
                    </div>
                </div>

                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Extension</label>
                    <span class="text-regal-navy-100 font-medium">{{ file.ext || '—' }}</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Source</label>
                    <span class="text-regal-navy-100 font-medium">{{ file.source }}</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Source ID</label>
                    <div v-if="file.source_id" class="flex items-center gap-1">
                        <span class="font-mono text-xs text-regal-navy-100">{{ file.source_id }}</span>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="() => copyToClipboard(file.source_id!, 'Source ID')"
                            class="p-1 h-auto"
                        >
                            <Copy :size="12" class="text-smart-blue-400" />
                        </Button>
                    </div>
                    <span v-else class="text-regal-navy-300 italic text-sm">—</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Type</label>
                    <span
                        class="px-3 py-1 rounded-sm text-xs font-medium inline-block"
                        :class="{
                            'bg-blue-500/20 text-blue-300': getMimeTypeCategory(file.mime_type) === 'image',
                            'bg-purple-500/20 text-purple-300': getMimeTypeCategory(file.mime_type) === 'video',
                            'bg-green-500/20 text-green-300': getMimeTypeCategory(file.mime_type) === 'audio',
                            'bg-twilight-indigo-500/20 text-twilight-indigo-700': getMimeTypeCategory(file.mime_type) === 'other',
                        }"
                    >
                        {{ file.mime_type || 'Unknown' }}
                    </span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Size</label>
                    <span class="text-regal-navy-100 font-medium">{{ formatFileSize(file.size) }}</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Hash</label>
                    <div v-if="file.hash" class="flex items-center gap-1">
                        <span class="font-mono text-xs text-regal-navy-100 break-all" :title="file.hash">
                            {{ file.hash }}
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="() => copyToClipboard(file.hash!, 'Hash')"
                            class="p-1 h-auto shrink-0"
                        >
                            <Copy :size="12" class="text-smart-blue-400" />
                        </Button>
                    </div>
                    <span v-else class="text-regal-navy-300 italic text-sm">—</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Downloaded</label>
                    <span
                        v-if="file.downloaded"
                        class="inline-flex items-center gap-1 px-3 py-1 rounded-sm text-xs font-medium bg-success-300 border border-success-500 text-success-900"
                    >
                        <Download :size="12" />
                        Yes
                    </span>
                    <span
                        v-else
                        class="px-3 py-1 rounded-sm text-xs font-medium bg-twilight-indigo-500 border border-blue-slate-500 text-twilight-indigo-100"
                    >
                        No
                    </span>
                </div>

                <div v-if="file.not_found">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Status</label>
                    <span class="px-3 py-1 rounded-sm text-xs font-medium bg-danger-300 border border-danger-600 text-danger-900">
                        Not Found
                    </span>
                </div>

                <div v-if="file.blacklisted_at">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Status</label>
                    <div class="space-y-1">
                        <span class="px-3 py-1 rounded-sm text-xs font-medium bg-danger-300 border border-danger-600 text-danger-900">
                            Blacklisted
                        </span>
                        <div v-if="file.blacklist_reason" class="text-xs text-regal-navy-100">
                            {{ file.blacklist_reason }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- URLs -->
        <div>
            <h5 class="text-lg font-semibold text-regal-navy-100 mb-4">URLs</h5>
            <div class="space-y-4">
                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">URL</label>
                    <div v-if="file.url" class="flex items-center gap-2">
                        <a
                            :href="file.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-smart-blue-400 hover:text-smart-blue-400 hover:underline truncate flex-1"
                            :title="file.url"
                        >
                            {{ file.url }}
                        </a>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="() => copyToClipboard(file.url!, 'URL')"
                            class="p-1 h-auto"
                        >
                            <Copy :size="12" class="text-smart-blue-400" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="() => openUrl(file.url!)"
                            class="p-1 h-auto"
                        >
                            <ExternalLink :size="12" class="text-smart-blue-400" />
                        </Button>
                    </div>
                    <span v-else class="text-regal-navy-300 italic text-sm">—</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Referrer URL</label>
                    <div v-if="file.referrer_url" class="flex items-center gap-2">
                        <a
                            :href="file.referrer_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-smart-blue-400 hover:text-smart-blue-400 hover:underline truncate flex-1"
                            :title="file.referrer_url"
                        >
                            {{ file.referrer_url }}
                        </a>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="() => copyToClipboard(file.referrer_url!, 'Referrer URL')"
                            class="p-1 h-auto"
                        >
                            <Copy :size="12" class="text-smart-blue-400" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="() => openUrl(file.referrer_url!)"
                            class="p-1 h-auto"
                        >
                            <ExternalLink :size="12" class="text-smart-blue-400" />
                        </Button>
                    </div>
                    <span v-else class="text-regal-navy-300 italic text-sm">—</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Thumbnail/Preview URL</label>
                    <div v-if="file.thumbnail_url" class="space-y-2">
                        <div class="flex items-center gap-2">
                            <a
                                :href="file.thumbnail_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-smart-blue-400 hover:text-smart-blue-400 hover:underline truncate flex-1"
                                :title="file.thumbnail_url"
                            >
                                {{ file.thumbnail_url }}
                            </a>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="() => copyToClipboard(file.thumbnail_url!, 'Thumbnail URL')"
                                class="p-1 h-auto"
                            >
                                <Copy :size="12" class="text-smart-blue-400" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="() => openUrl(file.thumbnail_url!)"
                                class="p-1 h-auto"
                            >
                                <ExternalLink :size="12" class="text-smart-blue-400" />
                            </Button>
                        </div>
                        <div class="mt-2">
                            <img
                                :src="file.thumbnail_url"
                                :alt="`Thumbnail for ${file.filename}`"
                                class="max-w-xs max-h-48 rounded border border-twilight-indigo-500"
                                @error="(e) => { (e.target as HTMLImageElement).style.display = 'none' }"
                            />
                        </div>
                    </div>
                    <span v-else class="text-regal-navy-300 italic text-sm">—</span>
                </div>
            </div>
        </div>

        <!-- Paths -->
        <div>
            <h5 class="text-lg font-semibold text-regal-navy-100 mb-4">Paths</h5>
            <div class="space-y-4">
                <div v-if="file.absolute_path">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">File Path</label>
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-xs text-regal-navy-100 break-all" :title="file.absolute_path">
                            {{ file.absolute_path }}
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="() => copyToClipboard(file.absolute_path!, 'Path')"
                            class="p-1 h-auto shrink-0"
                        >
                            <Copy :size="12" class="text-smart-blue-400" />
                        </Button>
                    </div>
                </div>

                <div v-if="file.thumbnail_path">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Thumbnail Path</label>
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-xs text-regal-navy-100 break-all" :title="file.thumbnail_path">
                            {{ file.thumbnail_path }}
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="() => copyToClipboard(file.thumbnail_path!, 'Thumbnail Path')"
                            class="p-1 h-auto shrink-0"
                        >
                            <Copy :size="12" class="text-smart-blue-400" />
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Metadata -->
        <div>
            <h5 class="text-lg font-semibold text-regal-navy-100 mb-4">Metadata</h5>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div v-if="file.title">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Title</label>
                    <span class="text-regal-navy-100 font-medium">{{ file.title }}</span>
                </div>

                <div v-if="file.description">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Description</label>
                    <p class="text-regal-navy-100 text-sm font-medium">{{ file.description }}</p>
                </div>

                <div v-if="file.tags && file.tags.length > 0" class="md:col-span-2">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Tags</label>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="tag in file.tags"
                            :key="tag"
                            class="px-2 py-1 rounded text-xs font-medium bg-smart-blue-700/30 text-smart-blue-100 border border-smart-blue-500/50"
                        >
                            {{ tag }}
                        </span>
                    </div>
                </div>

                <div v-if="file.parent_id">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Parent ID</label>
                    <span class="text-regal-navy-100 font-medium">{{ file.parent_id }}</span>
                </div>

                <div v-if="file.chapter">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Chapter</label>
                    <span class="text-regal-navy-100 font-medium">{{ file.chapter }}</span>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div>
            <h5 class="text-lg font-semibold text-regal-navy-100 mb-4">Statistics</h5>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Previewed Count</label>
                    <span class="text-regal-navy-100 font-medium">{{ file.previewed_count }}</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Seen Count</label>
                    <span class="text-regal-navy-100 font-medium">{{ file.seen_count }}</span>
                </div>

                <div v-if="file.download_progress > 0">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Download Progress</label>
                    <div class="flex items-center gap-2">
                        <div class="flex-1 bg-twilight-indigo-500 rounded-full h-2">
                            <div
                                class="bg-smart-blue-600 h-2 rounded-full transition-all"
                                :style="{ width: `${file.download_progress}%` }"
                            ></div>
                        </div>
                        <span class="text-sm text-regal-navy-100">{{ file.download_progress }}%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Timestamps -->
        <div>
            <h5 class="text-lg font-semibold text-regal-navy-100 mb-4">Timestamps</h5>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Created At</label>
                    <span class="text-regal-navy-100 font-medium">{{ formatDate(file.created_at) }}</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Last Updated</label>
                    <span class="text-regal-navy-100 font-medium">{{ formatDate(file.updated_at) }}</span>
                </div>

                <div v-if="file.downloaded_at">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Downloaded At</label>
                    <span class="text-regal-navy-100 font-medium">{{ formatDate(file.downloaded_at) }}</span>
                </div>

                <div v-if="file.previewed_at">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Last Previewed</label>
                    <span class="text-regal-navy-100 font-medium">{{ formatDate(file.previewed_at) }}</span>
                </div>

                <div v-if="file.seen_at">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Last Seen</label>
                    <span class="text-regal-navy-100 font-medium">{{ formatDate(file.seen_at) }}</span>
                </div>

                <div v-if="file.blacklisted_at">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Blacklisted At</label>
                    <span class="text-regal-navy-100 font-medium">{{ formatDate(file.blacklisted_at) }}</span>
                </div>
            </div>
        </div>

        <!-- Metadata Objects -->
        <div v-if="file.listing_metadata || file.detail_metadata">
            <h5 class="text-lg font-semibold text-regal-navy-100 mb-4">Additional Metadata</h5>
            <div class="space-y-4">
                <div v-if="file.listing_metadata">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Listing Metadata</label>
                    <pre class="bg-prussian-blue-600 p-3 rounded border border-twilight-indigo-500 text-xs text-twilight-indigo-700 overflow-x-auto">{{ JSON.stringify(file.listing_metadata, null, 2) }}</pre>
                </div>

                <div v-if="file.detail_metadata">
                    <label class="text-xs font-semibold text-smart-blue-300 uppercase tracking-wide mb-2 block">Detail Metadata</label>
                    <pre class="bg-prussian-blue-600 p-3 rounded border border-twilight-indigo-500 text-xs text-twilight-indigo-700 overflow-x-auto">{{ JSON.stringify(file.detail_metadata, null, 2) }}</pre>
                </div>
            </div>
        </div>
    </div>
</template>

