import { ref, reactive } from 'vue';
import type { MenuOptions } from '@imengyu/vue3-context-menu';

const show = ref(false);
const options = reactive<MenuOptions>({
    x: 0,
    y: 0,
    zIndex: 9999,
    minWidth: 230,
    // x: 500,
    // y: 200,
    theme: 'default'
});

type Content = {
    handler: 'audio-list' | 'video-list' | 'files-list' | 'users-list' | 'playlists-list';
    // null or object with attributes id, name
    item: null | { id: number; name: string };
}

function handleContextMenu(event: MouseEvent, content: Content) {
    console.log('Context menu event:', event, content);

    // Update menu position
    options.x = event.clientX;
    options.y = event.clientY;

    // Show the menu
    show.value = true;
}

export default function useContextMenu() {
    return { show, options, handleContextMenu };
}
