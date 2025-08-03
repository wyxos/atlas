import type { MenuOptions } from '@imengyu/vue3-context-menu';
import axios from 'axios';
import { reactive, ref } from 'vue';

const show = ref(false);
const loading = ref(false); // Add loading state

const options = reactive<MenuOptions>({
    x: 0,
    y: 0,
    zIndex: 9999,
    minWidth: 230,
    theme: 'win10 dark',
    data: null, // Store AJAX response data here
});

type Content = {
    handler: 'audio-list' | 'video-list' | 'files-list' | 'users-list' | 'playlists-list' | 'browse-list';
    // null or object with attributes id, name
    item: null | { id: number; name: string };
};

// Store the current context menu content
const currentContent = ref<Content | null>(null);

function handleContextMenu(event: MouseEvent, content: Content, endpoint?: string) {
    // Store the current content for use in menu actions
    currentContent.value = content;

    // Update menu position
    options.x = event.clientX;
    options.y = event.clientY;
    
    // Clear previous data
    options.data = null;

    if (endpoint) {
        // Only perform AJAX if endpoint is provided
        loading.value = true;
        
        axios
            .get(endpoint)
            .then((response) => {
                // Attach response data to options for use in context menu
                options.data = response.data;
            })
            .catch((error) => {
                console.error('Failed to load context menu data:', error);
                options.data = null;
            })
            .finally(() => {
                loading.value = false;
                show.value = true;
            });
    } else {
        // No endpoint provided, show menu immediately
        show.value = true;
    }
}

export default function useContextMenu() {
    return { show, options, currentContent, handleContextMenu, loading };
}
