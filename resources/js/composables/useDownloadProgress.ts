import { ref } from 'vue';
import { useEchoPublic } from '@laravel/echo-vue';

export function useDownloadProgress() {
    const downloadProgress = ref<Record<number, number>>({});
    const downloadedItems = ref<Set<number>>(new Set());

    // Setup Echo listener for download progress using useEchoPublic composable for public channel
    useEchoPublic('file-download-progress', 'FileDownloadProgress', (e: any) => {
        console.log('Received download progress event:', e);
        downloadProgress.value[e.fileId] = e.progress;

        if (e.progress === 100) {
            downloadedItems.value.add(e.fileId);
            // Remove progress after a delay
            setTimeout(() => {
                delete downloadProgress.value[e.fileId];
            }, 2000);
        }
    });

    return {
        downloadProgress,
        downloadedItems,
    };
}
