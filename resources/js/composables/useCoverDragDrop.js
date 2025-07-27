import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { audioStore } from '@/stores/audioStore';

export function useCoverDragDrop() {
    const isDragging = ref(false);

    const handleDragEnter = (event) => {
        event.preventDefault();
        isDragging.value = true;
    };

    const handleDragOver = (event) => {
        event.preventDefault();
    };

    const handleDragLeave = (event) => {
        event.preventDefault();
        // Only set isDragging to false if we're actually leaving the drop zone
        // Check if the related target is outside the current target
        const rect = event.currentTarget.getBoundingClientRect();
        const x = event.clientX;
        const y = event.clientY;

        if (x < rect.left || x > rect.right || y < rect.top || y > rect.bottom) {
            isDragging.value = false;
        }
    };

    const handleDrop = async (event) => {
        event.preventDefault();
        isDragging.value = false;

        const files = event.dataTransfer?.files;
        if (!files || files.length === 0) return;

        const file = files[0];
        if (!file.type.startsWith('image/')) {
            alert('Please drop an image file');
            return;
        }

        if (!audioStore.currentFile) return;

        // Get the cover ID - prioritize album covers first, then file covers
        let coverId = null;

        // First check for album covers
        if (audioStore.currentFile.albums && audioStore.currentFile.albums.length > 0) {
            for (const album of audioStore.currentFile.albums) {
                if (album.covers && album.covers.length > 0) {
                    coverId = album.covers[0].id;
                    break;
                }
            }
        }

        // Fall back to file covers
        if (!coverId && audioStore.currentFile.covers && audioStore.currentFile.covers.length > 0) {
            coverId = audioStore.currentFile.covers[0].id;
        }

        try {
            if (coverId) {
                // Update existing cover
                router.post(
                    route('covers.update', { coverId: coverId }),
                    {
                        file: file,
                    },
                    {
                        forceFormData: true,
                        preserveScroll: true,
                        onSuccess: () => {
                            // The page will be refreshed with updated covers
                        },
                        onError: (errors) => {
                            console.error('Error uploading cover:', errors);
                            alert('Failed to upload cover image');
                        },
                    },
                );
            } else {
                // Create new cover for the file
                router.post(
                    route('covers.create', { fileId: audioStore.currentFile.id }),
                    {
                        file: file,
                    },
                    {
                        forceFormData: true,
                        preserveScroll: true,
                        onSuccess: () => {
                            // The page will be refreshed with new cover
                        },
                        onError: (errors) => {
                            console.error('Error creating cover:', errors);
                            alert('Failed to create cover image');
                        },
                    },
                );
            }
        } catch (error) {
            console.error('Error handling cover:', error);
            alert('Failed to handle cover image');
        }
    };

    return {
        isDragging,
        handleDragEnter,
        handleDragOver,
        handleDragLeave,
        handleDrop,
    };
}
