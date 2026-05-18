import { ref, watch } from 'vue';

export const AUDIO_PLAYLIST_PANEL_OPEN_STORAGE_KEY = 'atlas:audioPlaylistPanelOpen';

function readPlaylistPanelOpenState(): boolean {
    if (typeof window === 'undefined' || !('sessionStorage' in window)) {
        return false;
    }

    try {
        const raw = window.sessionStorage.getItem(AUDIO_PLAYLIST_PANEL_OPEN_STORAGE_KEY);

        return raw === '1' || raw === 'true';
    } catch {
        return false;
    }
}

function writePlaylistPanelOpenState(isOpen: boolean): void {
    if (typeof window === 'undefined' || !('sessionStorage' in window)) {
        return;
    }

    try {
        window.sessionStorage.setItem(AUDIO_PLAYLIST_PANEL_OPEN_STORAGE_KEY, isOpen ? '1' : '0');
    } catch {
        // Ignore storage errors (private mode, quota, etc.).
    }
}

export function useAudioPlaylistPanelOpenState() {
    const isPlaylistPanelOpen = ref(readPlaylistPanelOpenState());

    watch(isPlaylistPanelOpen, (isOpen) => {
        writePlaylistPanelOpenState(isOpen);
    });

    return {
        isPlaylistPanelOpen,
    };
}
