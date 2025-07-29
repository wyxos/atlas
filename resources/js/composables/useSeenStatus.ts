import axios from 'axios';

export function useSeenStatus() {
    const markAsSeen = async (id: number, type: 'preview' | 'file') => {
        try {
            const response = await axios.post(route('files.seen', { file: id }), {
                type
            });
            return response.data;
        } catch (error) {
            console.error('Failed to mark file as seen:', error);
            throw error;
        }
    };

    return { markAsSeen };
}

