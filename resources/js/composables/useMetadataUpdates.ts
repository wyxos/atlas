import { useEchoPublic } from '@laravel/echo-vue';
import { ref } from 'vue';
import axios from 'axios';

export function useMetadataUpdates() {
    const updatedMetadata = ref<Record<number, any>>({});
    const updatedListingMetadata = ref<Record<number, any>>({});

    // Setup Echo listener for metadata updates using useEchoPublic composable for public channel
    useEchoPublic('file-metadata-updated', 'FileMetadataUpdated', async (e: any) => {
        try {
            // Fetch the updated metadata via AJAX to avoid Pusher payload size limits
            const response = await axios.get(`/files/${e.fileId}/metadata`);
            if (response.data?.metadata !== undefined) {
                updatedMetadata.value[e.fileId] = response.data.metadata;
            }
            if (response.data?.listing_metadata !== undefined) {
                updatedListingMetadata.value[e.fileId] = response.data.listing_metadata;
            }
        } catch (error) {
            console.error('Failed to fetch updated metadata for file:', e.fileId, error);
        }
    });

    return {
        updatedMetadata,
        updatedListingMetadata,
    };
}
