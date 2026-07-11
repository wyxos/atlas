import { reactive, ref, watch } from 'vue';

type OverlayMediaType = 'image' | 'video' | 'audio' | 'file';

const FILE_VIEWER_SHEET_OPEN_STORAGE_KEY = 'atlas:fileViewerSheetOpen';

function readSheetOpenPreference(storageKey: string | null): boolean | null {
    if (!storageKey || typeof window === 'undefined' || !('localStorage' in window)) {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(storageKey);
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

function writeSheetOpenPreference(storageKey: string | null, value: boolean): void {
    if (!storageKey || typeof window === 'undefined' || !('localStorage' in window)) {
        return;
    }

    try {
        window.localStorage.setItem(storageKey, value ? '1' : '0');
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
    autoOpenForFileMedia?: boolean;
    storageKey?: string | null;
}) {
    const autoOpenForFileMedia = params.autoOpenForFileMedia ?? true;
    const storageKey = params.storageKey === undefined
        ? FILE_VIEWER_SHEET_OPEN_STORAGE_KEY
        : params.storageKey;
    const sheetState = reactive({
        isOpen: false,
    });

    const sheetOpenPreference = ref<boolean | null>(readSheetOpenPreference(storageKey));

    if (sheetOpenPreference.value !== null) {
        sheetState.isOpen = sheetOpenPreference.value;
    }

    function setSheetOpen(isOpen: boolean, options?: { persist?: boolean }): void {
        sheetState.isOpen = isOpen;

        if (options?.persist === false) {
            return;
        }

        sheetOpenPreference.value = isOpen;
        writeSheetOpenPreference(storageKey, isOpen);
    }

    watch(
        () => [params.overlay.mediaType, params.overlay.fillComplete, params.overlay.isClosing],
        ([mediaType, filled, isClosing]) => {
            if (autoOpenForFileMedia && mediaType === 'file' && filled && !isClosing && sheetOpenPreference.value !== false) {
                setSheetOpen(true, { persist: false });
            }
        },
    );

    return {
        sheetState,
        setSheetOpen,
    };
}
