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
});

type Content = {
    handler: 'audio-list' | 'video-list' | 'files-list' | 'users-list' | 'playlists-list' | 'browse-list';
    // null or object with attributes id, name
    item: null | { id: number; name: string };
};

// Store the current context menu content
const currentContent = ref<Content | null>(null);

function handleContextMenu(event: MouseEvent, content: Content) {
    // Store the current content for use in menu actions
    currentContent.value = content;

    // Update menu position
    options.x = event.clientX;
    options.y = event.clientY;
    loading.value = true; // Set loading to true

    // Perform AJAX request
    axios
        .get('/api/some-endpoint') // Replace with your endpoint
        .then((response) => {
            // Handle response data
        })
        .finally(() => {
            loading.value = false; // Set loading to false when completed
            show.value = true;
        });
}

export default function useContextMenu() {
    return { show, options, currentContent, handleContextMenu, loading };
}
