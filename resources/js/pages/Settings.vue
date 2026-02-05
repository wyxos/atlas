<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import PageLayout from '../components/PageLayout.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogClose,
} from '@/components/ui/dialog';
import { deleteAll as deleteAllTabs } from '@/actions/App/Http/Controllers/TabController';
import { deleteAll as deleteAllFiles } from '@/actions/App/Http/Controllers/FilesController';
import { AlertTriangle } from 'lucide-vue-next';
import extensionManifest from '../../../extension/atlas-downloader/manifest.json';

const router = useRouter();
const resetDialogOpen = ref(false);
const isResetting = ref(false);
const extensionDialogOpen = ref(false);
const extensionVersion = computed(() => extensionManifest.version ?? 'dev');
const extensionDownloadUrl = '/downloads/atlas-extension.zip';

async function handleResetApp(): Promise<void> {
    isResetting.value = true;
    try {
        // Delete all files first
        await window.axios.delete(deleteAllFiles.url());

        // Then delete all tabs
        await window.axios.delete(deleteAllTabs.url());

        // Close dialog
        resetDialogOpen.value = false;

        // Redirect to home page
        router.push('/');
    } catch (error) {
        console.error('Failed to reset app:', error);
        // Error is logged, user can try again
    } finally {
        isResetting.value = false;
    }
}
</script>

<template>
    <PageLayout>
        <div>
            <h4 class="text-2xl font-semibold text-regal-navy-100 mb-4">Settings</h4>

            <div class="space-y-6">
                <div class="border border-smart-blue-500/30 rounded-lg p-6 bg-prussian-blue-700/50">
                    <h5 class="text-lg font-semibold text-smart-blue-300 mb-2">Atlas Browser Extension</h5>
                    <p class="text-twilight-indigo-200 mb-4">
                        Install the extension to send large images and videos directly to Atlas from any site.
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button @click="extensionDialogOpen = true" variant="outline">
                            Install Extension
                        </Button>
                        <Button as="a" :href="extensionDownloadUrl" variant="secondary">
                            Download Zip
                        </Button>
                        <span class="text-xs text-twilight-indigo-300">v{{ extensionVersion }}</span>
                    </div>
                </div>

                <div class="border border-danger-500/30 rounded-lg p-6 bg-prussian-blue-700/50">
                    <h5 class="text-lg font-semibold text-danger-400 mb-2">Danger Zone</h5>
                    <p class="text-twilight-indigo-200 mb-4">
                        Reset the app to its initial state. This will permanently delete all tabs and files.
                    </p>
                    <Button @click="resetDialogOpen = true" variant="destructive" data-test="reset-app-button">
                        Reset App
                    </Button>
                </div>
            </div>
        </div>

        <!-- Reset App Confirmation Dialog -->
        <Dialog v-model="resetDialogOpen">
            <DialogContent class="sm:max-w-[425px] bg-prussian-blue-600 border-danger-500/30">
                <DialogHeader>
                    <DialogTitle class="text-danger-400 flex items-center gap-2">
                        <AlertTriangle :size="20" />
                        Reset App
                    </DialogTitle>
                    <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                        Are you sure you want to reset the app? This will permanently delete all tabs and files. This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" @click="resetDialogOpen = false" :disabled="isResetting">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button @click="handleResetApp" variant="destructive" :loading="isResetting" data-test="confirm-reset-button">
                        Reset
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model="extensionDialogOpen">
            <DialogContent class="sm:max-w-[520px] bg-prussian-blue-600 border-smart-blue-500/30">
                <DialogHeader>
                    <DialogTitle class="text-smart-blue-300">Install the Atlas Extension</DialogTitle>
                    <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                        Load the extension from this repo and connect it to your Atlas instance.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-4 text-sm text-twilight-indigo-100">
                    <ol class="list-decimal pl-5 space-y-2">
                        <li>
                            Open <span class="font-semibold">chrome://extensions</span> (Chrome) or
                            <span class="font-semibold">brave://extensions</span> (Brave).
                        </li>
                        <li>Enable Developer Mode.</li>
                        <li>Click “Load unpacked” and select <span class="font-semibold">extension/atlas-downloader</span>.</li>
                        <li>Generate a token with <span class="font-semibold">php artisan atlas:extension-token --set</span>.</li>
                        <li>Open the extension options and set your Atlas base URL and <span class="font-semibold">ATLAS_EXTENSION_TOKEN</span>.</li>
                    </ol>
                    <div class="flex flex-wrap gap-2">
                        <Button as="a" href="chrome://extensions" target="_blank" rel="noopener noreferrer" variant="outline" size="sm">
                            Open Chrome Extensions
                        </Button>
                        <Button as="a" href="brave://extensions" target="_blank" rel="noopener noreferrer" variant="outline" size="sm">
                            Open Brave Extensions
                        </Button>
                        <Button as="a" :href="extensionDownloadUrl" variant="secondary" size="sm">
                            Download Zip (v{{ extensionVersion }})
                        </Button>
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" @click="extensionDialogOpen = false">
                            Close
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </PageLayout>
</template>
