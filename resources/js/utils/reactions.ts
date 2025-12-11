/**
 * Creates a callback function for queuing reactions
 * This standardizes the reaction API call pattern across the application
 */
export function createReactionCallback(): (
    fileId: number,
    type: 'love' | 'like' | 'dislike' | 'funny'
) => Promise<void> {
    return async (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => {
        try {
            await window.axios.post(`/api/files/${fileId}/reaction`, { type });
        } catch (error) {
            console.error('Failed to update reaction:', error);
            throw error;
        }
    };
}

