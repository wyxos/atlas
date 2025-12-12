<script setup lang="ts">
import { show, destroy } from '@/actions/App/Http/Controllers/FilesController';
import { ref, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Download, FileText, Copy, ExternalLink, Trash2, Eye, Info } from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import FileDetailsCard from '../components/FileDetailsCard.vue';
import FileDetailsPanel from '../components/ui/FileDetailsPanel.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogClose,
} from '../components/ui/dialog';
import { formatFileSize, getMimeTypeCategory } from '@/utils/file';
import type { File } from '@/types/file';

const route = useRoute();
const router = useRouter();

const file = ref<File | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const dialogOpen = ref(false);
const deleteError = ref<string | null>(null);
const canRetryDelete = ref(false);
const deleting = ref(false);
const detailsPanelOpen = ref(false);


const fileUrl = computed(() => {
    if (!file.value) {
        return null;
    }
    // Use file_url if available (generated from path), otherwise use url
    return file.value.file_url || file.value.url;
});

const fileType = computed(() => {
    if (!file.value) {
        return 'unknown';
    }
    return getMimeTypeCategory(file.value.mime_type);
});


async function loadFile(): Promise<void> {
    const fileIdParam = route.params.id as string;
    const fileId = Number.parseInt(fileIdParam, 10);
    loading.value = true;
    error.value = null;

    try {
        const response = await window.axios.get<{ file: File }>(show.url(fileId));
        file.value = response.data.file;
    } catch (err: unknown) {
        const axiosError = err as { response?: { status?: number; data?: { message?: string } } };
        const statusCode = axiosError.response?.status;

        if (statusCode === 404) {
            error.value = 'File not found.';
        } else if (statusCode === 403) {
            error.value = 'You do not have permission to view this file.';
        } else {
            error.value = axiosError.response?.data?.message || 'Failed to load file. Please try again later.';
        }
    } finally {
        loading.value = false;
    }
}

async function deleteFile(): Promise<void> {
    if (!file.value) {
        return;
    }

    deleting.value = true;
    deleteError.value = null;
    canRetryDelete.value = false;

    try {
        await window.axios.delete(`/api/files/${file.value.id}`);
        toast.success('File deleted successfully');
        router.push('/files');
    } catch (err: unknown) {
        const axiosError = err as { response?: { status?: number; data?: { message?: string } } };
        const statusCode = axiosError.response?.status;

        if (statusCode === 403) {
            deleteError.value = 'You do not have permission to delete this file.';
            canRetryDelete.value = false;
        } else if (statusCode && statusCode >= 500) {
            deleteError.value = 'Something went wrong while deleting the file. Please try again.';
            canRetryDelete.value = true;
        } else {
            deleteError.value = axiosError.response?.data?.message || 'Failed to delete file. Please try again later.';
            canRetryDelete.value = false;
        }
    } finally {
        deleting.value = false;
    }
}

function openDeleteDialog(): void {
    dialogOpen.value = true;
    deleteError.value = null;
    canRetryDelete.value = false;
}

function handleDeleteCancel(): void {
    dialogOpen.value = false;
    deleteError.value = null;
    canRetryDelete.value = false;
}

async function handleDeleteConfirm(): Promise<void> {
    await deleteFile();
    if (!deleteError.value) {
        dialogOpen.value = false;
    }
}


onMounted(() => {
    loadFile();
});
</script>

<template>
    <div class="w-full flex flex-col h-full md:flex-1 md:min-h-0 md:overflow-hidden">
        <!-- Loading State -->
        <div v-if="loading" class="text-center py-12">
            <p class="text-twilight-indigo-100 text-lg">Loading file...</p>
        </div>

        <!-- Error State -->
        <div v-else-if="error" class="text-center py-12">
            <p class="text-red-500 text-lg">{{ error }}</p>
            <Button variant="outline" @click="loadFile" class="mt-4">
                Retry
            </Button>
        </div>

        <!-- File Details -->
        <div v-else-if="file" class="flex flex-col h-full md:flex-1 md:min-h-0 md:overflow-hidden">
            <!-- Actions - Mobile: Outside at top, Desktop: Inside preview -->
            <div class="flex items-center justify-between mb-4 md:hidden shrink-0">
                <Button variant="ghost" size="sm" @click="() => router.push('/files')"
                    class="flex items-center justify-center h-16 w-16 md:h-10 md:w-10 rounded-lg bg-smart-blue-500 border-2 border-smart-blue-400 text-white hover:bg-smart-blue-400"
                    title="Back to Files">
                    <ArrowLeft :size="40" class="text-white block md:hidden" />
                    <ArrowLeft :size="28" class="text-white hidden md:block" />
                </Button>
                <div class="flex items-center gap-2">
                    <Button variant="ghost" size="sm" @click="detailsPanelOpen = true"
                        class="flex items-center justify-center h-16 w-16 md:h-10 md:w-10 rounded-lg bg-smart-blue-500 border-2 border-smart-blue-400 hover:bg-smart-blue-400"
                        title="View Details">
                        <Info :size="40" class="text-white block md:hidden" />
                        <Info :size="28" class="text-white hidden md:block" />
                    </Button>
                    <Button variant="ghost" size="sm" @click="openDeleteDialog"
                        class="flex items-center justify-center h-16 w-16 md:h-10 md:w-10 rounded-lg bg-danger-400 border-2 border-danger-300 hover:bg-danger-300"
                        title="Delete File">
                        <Trash2 :size="40" class="text-white block md:hidden" />
                        <Trash2 :size="28" class="text-white hidden md:block" />
                    </Button>
                </div>
            </div>
            <!-- File Preview -->
            <div
                class="flex-1 flex items-center justify-center min-h-0 overflow-hidden bg-prussian-blue-800 p-6 relative">
                <!-- Actions - Desktop: Inside preview -->
                <div class="hidden md:flex absolute top-4 left-4 right-4 items-center justify-between z-10">
                    <Button variant="ghost" size="sm" @click="() => router.push('/files')"
                        class="flex items-center justify-center h-16 w-16 md:h-10 md:w-10 rounded-lg bg-smart-blue-500 border-2 border-smart-blue-400 hover:bg-smart-blue-400"
                        title="Back to Files">
                        <ArrowLeft :size="40" class="text-white block md:hidden" />
                        <ArrowLeft :size="28" class="text-white hidden md:block" />
                    </Button>
                    <div class="flex items-center gap-2">
                        <Button variant="ghost" size="sm" @click="detailsPanelOpen = true"
                            class="flex items-center justify-center h-16 w-16 md:h-10 md:w-10 rounded-lg bg-smart-blue-500 border-2 border-smart-blue-400 hover:bg-smart-blue-400"
                            title="View Details">
                            <Info :size="40" class="text-white block md:hidden" />
                            <Info :size="28" class="text-white hidden md:block" />
                        </Button>
                        <Button variant="ghost" size="sm" @click="openDeleteDialog"
                            class="flex items-center justify-center h-16 w-16 md:h-10 md:w-10 rounded-lg bg-danger-400 border-2 border-danger-300 hover:bg-danger-300"
                            title="Delete File">
                            <Trash2 :size="40" class="text-white block md:hidden" />
                            <Trash2 :size="28" class="text-white hidden md:block" />
                        </Button>
                    </div>
                </div>
                <img v-if="fileType === 'image' && fileUrl" :src="fileUrl" :alt="file.title || file.filename"
                    class="max-w-full max-h-full rounded object-contain"
                    @error="(e) => { (e.target as HTMLImageElement).style.display = 'none' }" />
                <video v-else-if="fileType === 'video' && fileUrl" :src="fileUrl" controls
                    class="w-full h-full object-contain rounded">
                    Your browser does not support the video tag.
                </video>
                <audio v-else-if="fileType === 'audio' && fileUrl" :src="fileUrl" controls class="w-full max-w-2xl">
                    Your browser does not support the audio tag.
                </audio>
                <div v-else class="text-center py-12">
                    <FileText :size="64" class="text-twilight-indigo-400 mx-auto mb-4" />
                    <p class="text-twilight-indigo-100 text-lg">
                        {{ fileUrl ? 'Preview not available for this file type' : 'File not available for preview' }}
                    </p>
                    <p v-if="!fileUrl" class="text-twilight-indigo-300 text-sm mt-2">
                        This file has no URL or local path.
                    </p>
                </div>
            </div>

            <!-- File Details Panel -->
            <FileDetailsPanel :modelValue="detailsPanelOpen" @update:modelValue="(value) => detailsPanelOpen = value"
                title="File Details">
                <FileDetailsCard :file="file" />
            </FileDetailsPanel>
        </div>

        <!-- Delete Confirmation Dialog -->
        <Dialog v-model="dialogOpen">
            <DialogContent class="sm:max-w-[425px] bg-prussian-blue-600 border-danger-500/30">
                <DialogHeader>
                    <DialogTitle class="text-danger-400">Delete File</DialogTitle>
                    <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                        Are you sure you want to delete <span class="font-semibold text-danger-400">{{ file?.filename
                        }}</span>? This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <div v-if="deleteError"
                    class="mt-4 rounded border border-danger-400 bg-danger-300/20 px-3 py-2 text-sm text-danger-700">
                    {{ deleteError }}
                </div>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" @click="handleDeleteCancel" :disabled="deleting">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button v-if="canRetryDelete || !deleteError" @click="handleDeleteConfirm" :disabled="deleting"
                        variant="destructive">
                        {{ deleting ? 'Deleting...' : (deleteError && canRetryDelete ? 'Retry' : 'Delete') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
