<script setup lang="ts">
import { onMounted, watch, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Trash2, Filter, File as FileIcon, Download, Eye, XCircle } from 'lucide-vue-next';
import { toast } from '../components/ui/sonner';
import PageLayout from '../components/PageLayout.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogClose,
} from '../components/ui/dialog';
import { Button } from '@/components/ui/button';
import FilterPanel from '../components/ui/FilterPanel.vue';
import Select from '../components/ui/Select.vue';
import ListingFilterForm from '../components/ListingFilterForm.vue';
import ActiveFilters from '../components/ActiveFilters.vue';
import ListingTable from '../components/ListingTable.vue';
import { Listing } from '../lib/Listing';
import { DeletionHandler } from '../lib/DeletionHandler';
import { formatDate } from '../utils/date';

const route = useRoute();
const router = useRouter();

interface File extends Record<string, unknown> {
    id: number;
    source: string;
    filename: string;
    ext: string | null;
    size: number | null;
    mime_type: string | null;
    title: string | null;
    url: string | null;
    referrer_url: string | null;
    path: string | null;
    absolute_path: string | null;
    thumbnail_url: string | null;
    downloaded: boolean;
    not_found: boolean;
    created_at: string;
    updated_at: string;
}

// Create reactive listing instance (Listing.create returns a reactive Proxy)
// Proxy provides dynamic filter properties (e.g., listing.search, listing.date_from)
// TypeScript can't infer dynamic Proxy properties, so we cast to any
const listing = Listing.create<File>({
    filters: {
        search: '',
        date_from: '',
        date_to: '',
        source: 'all',
        mime_type: 'all',
        downloaded: 'all',
    },
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
}) as any;
listing.loading(); // Initial loading state

// Configure listing with path, router, filters, and error handler
listing
    .path('/api/files')
    .router(router)
    .onLoadError((error: string | null, statusCode?: number) => {
        // Customize error messages for files context
        if (statusCode === 403) {
            return 'You do not have permission to view files.';
        }
        if (error && error.includes('Failed to load data')) {
            return 'Failed to load files. Please try again later.';
        }
        return error;
    });

const deletionHandler = DeletionHandler.create<File>(listing, {
    getDeleteUrl: (file) => `/api/files/${file.id}`,
    getId: (file) => file.id,
    permissionDeniedMessage: 'You do not have permission to delete files.',
    serverErrorMessage: 'Something went wrong while deleting the file. Please try again.',
    genericErrorMessage: 'Failed to delete file. Please try again later.',
});

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
    } catch (error) {
        toast.error('Failed to copy to clipboard', {
            description: 'Please try again or copy manually',
        });
        console.error('Error copying to clipboard:', error);
    }
}

const hasActiveFilters = computed(() => listing.hasActiveFilters);

// Watch for route query changes (back/forward navigation)
watch(() => route.query, async (newQuery) => {
    await listing.get({ query: newQuery });
}, { deep: true });

// Expose properties for testing
defineExpose({
    listing,
    get currentPage() {
        return listing.currentPage;
    },
    get perPage() {
        return listing.perPage;
    },
    get total() {
        return listing.total;
    },
    get files() {
        return listing.data;
    },
    get loading() {
        return listing.isLoading;
    },
    get error() {
        return listing.error;
    },
    get activeFilters() {
        return listing.activeFilters;
    },
    get hasActiveFilters() {
        return listing.hasActiveFilters;
    },
});

onMounted(async () => {
    await listing.get({ query: route.query });
});
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <h4 class="text-2xl font-semibold mb-2 text-regal-navy-100">
                        Files
                    </h4>
                    <p class="text-blue-slate-300">
                        Manage your files
                    </p>
                </div>
                <Button variant="outline" @click="() => listing.openPanel()">
                    <Filter :size="16" class="mr-2" />
                    Filters
                </Button>
            </div>

            <!-- Active Filters Display -->
            <ActiveFilters :listing="listing" />

            <div v-if="listing.isLoading" class="text-center py-12">
                <p class="text-twilight-indigo-100 text-lg">Loading files...</p>
            </div>

            <div v-else-if="listing.error" class="text-center py-12">
                <p class="text-red-500 text-lg">{{ listing.error }}</p>
            </div>

            <div v-else class="w-full overflow-x-auto">
                <ListingTable :listing="listing" class="w-full overflow-hidden">
                    <o-table-column field="id" label="ID" width="80" />
                    <o-table-column field="filename" label="Filename">
                        <template #default="{ row }">
                            <span @click="() => copyToClipboard(row.filename, 'Filename')"
                                class="text-twilight-indigo-100 cursor-pointer hover:text-smart-blue-400 transition-colors truncate block"
                                :title="`Click to copy: ${row.filename}`">
                                {{ row.filename }}
                            </span>
                        </template>
                    </o-table-column>
                    <o-table-column field="source" label="Source" width="120" />
                    <o-table-column field="mime_type" label="Type" width="120">
                        <template #default="{ row }">
                            <span class="px-2 py-1 rounded text-xs font-medium" :class="{
                                'bg-blue-500/20 text-blue-300': getMimeTypeCategory(row.mime_type) === 'image',
                                'bg-purple-500/20 text-purple-300': getMimeTypeCategory(row.mime_type) === 'video',
                                'bg-green-500/20 text-green-300': getMimeTypeCategory(row.mime_type) === 'audio',
                                'bg-twilight-indigo-500/20 text-twilight-indigo-100': getMimeTypeCategory(row.mime_type) === 'other',
                            }">
                                {{ row.mime_type || 'Unknown' }}
                            </span>
                        </template>
                    </o-table-column>
                    <o-table-column field="size" label="Size" width="100">
                        <template #default="{ row }">
                            {{ formatFileSize(row.size) }}
                        </template>
                    </o-table-column>
                    <o-table-column field="downloaded" label="Downloaded" width="120">
                        <template #default="{ row }">
                            <span v-if="row.downloaded"
                                class="inline-flex items-center justify-center px-3 py-1 rounded-sm text-xs font-medium bg-success-700 border border-success-500 text-success-100">
                                <Download :size="16" />
                            </span>
                            <span v-else
                                class="inline-flex items-center justify-center px-3 py-1 rounded-sm text-xs font-medium bg-twilight-indigo-500 border border-blue-slate-500 text-twilight-indigo-100">
                                <XCircle :size="16" />
                            </span>
                        </template>
                    </o-table-column>
                    <o-table-column field="absolute_path" label="Path" width="300">
                        <template #default="{ row }">
                            <span v-if="row.absolute_path" @click="() => copyToClipboard(row.absolute_path, 'Path')"
                                class="font-mono text-xs text-twilight-indigo-100 cursor-pointer hover:text-smart-blue-400 transition-colors truncate block"
                                :title="`Click to copy: ${row.absolute_path}`">
                                {{ row.absolute_path }}
                            </span>
                            <span v-else class="text-twilight-indigo-500 italic text-xs">
                                —
                            </span>
                        </template>
                    </o-table-column>
                    <o-table-column field="url" label="URL" width="250">
                        <template #default="{ row }">
                            <a v-if="row.url" :href="row.url" target="_blank" rel="noopener noreferrer"
                                class="text-smart-blue-400 hover:text-smart-blue-400 hover:underline truncate flex-1 min-w-0 w-0 md:w-full md:max-w-xs block"
                                :title="row.url">
                                {{ row.url }}
                            </a>
                            <span v-else class="text-twilight-indigo-500 italic text-xs">
                                —
                            </span>
                        </template>
                    </o-table-column>
                    <o-table-column field="referrer_url" label="Referrer URL" width="250">
                        <template #default="{ row }">
                            <a v-if="row.referrer_url" :href="row.referrer_url" target="_blank"
                                rel="noopener noreferrer"
                                class="text-smart-blue-400 hover:text-smart-blue-400 hover:underline truncate flex-1 min-w-0 w-0 md:w-full md:max-w-xs block"
                                :title="row.referrer_url">
                                {{ row.referrer_url }}
                            </a>
                            <span v-else class="text-twilight-indigo-500 italic text-xs">
                                —
                            </span>
                        </template>
                    </o-table-column>
                    <o-table-column field="created_at" label="Created At" width="180">
                        <template #default="{ row }">
                            {{ formatDate(row.created_at) }}
                        </template>
                    </o-table-column>
                    <o-table-column field="updated_at" label="Last Updated" width="180">
                        <template #default="{ row }">
                            {{ formatDate(row.updated_at) }}
                        </template>
                    </o-table-column>
                    <o-table-column label="Actions" width="140">
                        <template #default="{ row }">
                            <div class="flex items-center justify-center gap-2">
                                <Button @click="() => router.push(`/files/${row.id}`)" variant="ghost" size="sm"
                                    class="flex items-center justify-center h-16 w-16 md:h-10 md:w-10 rounded-lg bg-smart-blue-500 border-2 border-smart-blue-400 text-white hover:bg-smart-blue-400"
                                    :title="`View ${row.filename}`">
                                    <Eye :size="40" class="text-white block md:hidden" />
                                    <Eye :size="28" class="text-white hidden md:block" />
                                </Button>
                                <Button @click="deletionHandler.openDialog(row)" variant="ghost" size="sm"
                                    class="flex items-center justify-center h-16 w-16 md:h-10 md:w-10 rounded-lg bg-danger-400 border-2 border-danger-300 text-white hover:bg-danger-700"
                                    :disabled="deletionHandler.isDeleting && deletionHandler.itemToDelete?.id === row.id"
                                    :title="`Delete ${row.filename}`">
                                    <Trash2 :size="40" class="text-white block md:hidden" />
                                    <Trash2 :size="28" class="text-white hidden md:block" />
                                </Button>
                            </div>
                        </template>
                    </o-table-column>
                    <template #empty>
                        <div class="flex flex-col items-center justify-center py-12 px-6">
                            <FileIcon :size="64" class="text-twilight-indigo-400 mb-4" />
                            <h3 class="text-xl font-semibold text-regal-navy-100 mb-2">
                                {{ hasActiveFilters ? 'No files found' : 'No files yet' }}
                            </h3>
                            <p class="text-twilight-indigo-300 text-center max-w-md">
                                {{ hasActiveFilters
                                    ? 'Try adjusting your filters to see more results.'
                                    : 'Get started by adding your first file.' }}
                            </p>
                            <Button v-if="hasActiveFilters" variant="outline" @click="() => listing.resetFilters()"
                                class="mt-4">
                                Clear
                            </Button>
                        </div>
                    </template>
                </ListingTable>
            </div>

            <!-- Filter Panel -->
            <FilterPanel :modelValue="listing.isPanelOpen()"
                @update:modelValue="(open) => open ? listing.openPanel() : listing.closePanel()" title="Filter Files"
                :is-filtering="listing.isFiltering" :is-resetting="listing.isResetting"
                @apply="() => listing.applyFilters()" @reset="() => listing.resetFilters()">
                <ListingFilterForm :search="listing.search" :date-from="listing.date_from" :date-to="listing.date_to"
                    search-placeholder="Search by filename, title, or source..."
                    @update:search="(value) => listing.search = value"
                    @update:date-from="(value) => listing.date_from = value"
                    @update:date-to="(value) => listing.date_to = value" @submit="() => listing.applyFilters()">
                    <!-- Source Filter -->
                    <Select v-model="listing.source">
                        <template #label>
                            Source
                        </template>
                        <option value="all">All</option>
                        <option value="local">Local</option>
                        <option value="NAS">NAS</option>
                        <option value="YouTube">YouTube</option>
                        <option value="Booru">Booru</option>
                    </Select>

                    <!-- MIME Type Filter -->
                    <Select v-model="listing.mime_type">
                        <template #label>
                            Type
                        </template>
                        <option value="all">All</option>
                        <option value="image">Image</option>
                        <option value="video">Video</option>
                        <option value="audio">Audio</option>
                    </Select>

                    <!-- Downloaded Filter -->
                    <Select v-model="listing.downloaded">
                        <template #label>
                            Downloaded
                        </template>
                        <option value="all">All</option>
                        <option value="yes">Yes</option>
                        <option value="no">No</option>
                    </Select>
                </ListingFilterForm>
            </FilterPanel>

            <!-- Delete Confirmation Dialog -->
            <Dialog v-model="deletionHandler.dialogOpen">
                <DialogContent class="sm:max-w-[425px] bg-prussian-blue-600 border-danger-500/30">
                    <DialogHeader>
                        <DialogTitle class="text-danger-400">Delete File</DialogTitle>
                        <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                            Are you sure you want to delete <span class="font-semibold text-danger-400">{{
                                deletionHandler.itemToDelete?.filename }}</span>? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <div v-if="deletionHandler.deleteError"
                        class="mt-4 rounded border border-danger-400 bg-danger-700/20 px-3 py-2 text-sm text-danger-300">
                        {{ deletionHandler.deleteError }}
                    </div>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button variant="outline" @click="deletionHandler.closeDialog"
                                :disabled="deletionHandler.isDeleting">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button v-if="deletionHandler.canRetryDelete || !deletionHandler.deleteError"
                            @click="() => deletionHandler.delete()" :disabled="deletionHandler.isDeleting"
                            variant="destructive">
                            {{ deletionHandler.isDeleting ? 'Deleting...' : (deletionHandler.deleteError &&
                                deletionHandler.canRetryDelete ? 'Retry' : 'Delete') }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </PageLayout>
</template>
