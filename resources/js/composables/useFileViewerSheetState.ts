import { reactive, ref, watch } from 'vue';

type OverlayMediaType = 'image' | 'video' | 'audio' | 'file';

const FILE_VIEWER_SHEET_OPEN_STORAGE_KEY = 'atlas:fileViewerSheetOpen';

function readSheetOpenPreference(): boolean | null {
    if (typeof window === 'undefined' || !('localStorage' in window)) {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(FILE_VIEWER_SHEET_OPEN_STORAGE_KEY);
        if (raw === null) {
            return null;
        }

        if (raw === '1' || raw === 'true') {
            return true;
        }

        if (raw === '0' || raw === 'false') {
            return false;
        }

        return null;
    } catch {
        return null;
    }
}

function writeSheetOpenPreference(value: boolean): void {
    if (typeof window === 'undefined' || !('localStorage' in window)) {
        return;
    }

    try {
        window.localStorage.setItem(FILE_VIEWER_SHEET_OPEN_STORAGE_KEY, value ? '1' : '0');
    } catch {
        // Ignore storage errors (private mode, quota, etc.).
    }
}

export function useFileViewerSheetState(params: {
    overlay: {
        mediaType: OverlayMediaType;
        fillComplete: boolean;
        isClosing: boolean;
    };
}) {
    const sheetState = reactive({
        isOpen: false,
    });

    const sheetOpenPreference = ref<boolean | null>(readSheetOpenPreference());

    if (sheetOpenPreference.value !== null) {
        sheetState.isOpen = sheetOpenPreference.value;
    }

    function setSheetOpen(isOpen: boolean, options?: { persist?: boolean }): void {
        sheetState.isOpen = isOpen;

        if (options?.persist === false) {
            return;
        }

        sheetOpenPreference.value = isOpen;
        writeSheetOpenPreference(isOpen);
    }

    watch(
        () => [params.overlay.mediaType, params.overlay.fillComplete, params.overlay.isClosing],
        ([mediaType, filled, isClosing]) => {
            if (mediaType === 'file' && filled && !isClosing && sheetOpenPreference.value !== false) {
                setSheetOpen(true, { persist: false });
            }
        },
    );

    return {
        sheetState,
        setSheetOpen,
    };
}
