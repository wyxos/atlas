import { useEchoPublic } from '@laravel/echo-vue';
import { ref } from 'vue';
import axios from 'axios';

export function useMetadataUpdates() {
    const updatedMetadata = ref<Record<number, any>>({});

    // Setup Echo listener for metadata updates using useEchoPublic composable for public channel
    useEchoPublic('file-metadata-updated', 'FileMetadataUpdated', async (e: any) => {
        console.log('Metadata update notification received for file:', e.fileId);
        
        try {
            // Fetch the updated metadata via AJAX to avoid Pusher payload size limits
            const response = await axios.get(`/files/${e.fileId}/metadata`);
            updatedMetadata.value[e.fileId] = response.data.metadata;
            console.log('Metadata fetched successfully for file:', e.fileId);
        } catch (error) {
            console.error('Failed to fetch updated metadata for file:', e.fileId, error);
        }
    });

    return {
        updatedMetadata,
    };
}
