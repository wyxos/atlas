<script setup lang="ts">
import { ref } from 'vue';
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

const router = useRouter();
const resetDialogOpen = ref(false);
const isResetting = ref(false);

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
                    <Button @click="handleResetApp" variant="destructive" :disabled="isResetting" data-test="confirm-reset-button">
                        <span v-if="isResetting">Resetting...</span>
                        <span v-else>Reset App</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </PageLayout>
</template>
