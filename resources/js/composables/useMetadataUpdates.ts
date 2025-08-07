import { useEchoPublic } from '@laravel/echo-vue';
import { ref } from 'vue';

export function useMetadataUpdates() {
    const updatedMetadata = ref<Record<number, any>>({});

    // Setup Echo listener for metadata updates using useEchoPublic composable for public channel
    useEchoPublic('file-metadata-updated', 'FileMetadataUpdated', (e: any) => {
        console.log('Metadata updated:', e);
        updatedMetadata.value[e.fileId] = e.metadata;
    });

    return {
        updatedMetadata,
    };
}
