import { ref } from 'vue';

const show = ref(true)


type Content = {
    handler: 'audio-list' | 'video-list' | 'files-list' | 'users-list' | 'playlists-list';
    // null or object with attributes id, name
    item: null | { id: number; name: string };
}
function handleContextMenu(event: MouseEvent, content: Content) {
    console.log('Context menu event:', event, content);
}

export default function useContextMenu() {

    return {show, handleContextMenu}
}
