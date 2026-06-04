import { ref, watch, toRefs, computed, type Ref } from 'vue';
import { incrementSeen, show as getFile } from '@/actions/App/Http/Controllers/FilesController';
import type { FeedItem } from '@/composables/useTabs';
import type { File } from '@/types/file';

function isSpotifyFile(file: File): boolean {
    return file.source?.trim().toLowerCase() === 'spotify'
        || file.mime_type?.trim().toLowerCase() === 'audio/spotify'
        || Boolean(file.spotify_uri?.trim());
}

export function useFileViewerData(params: {
    items: Ref<FeedItem[]>;
    navigation: {
        currentItemIndex: number | null;
    };
    overlay: {
        fillComplete: boolean;
    };
    sheet: {
        isOpen: boolean;
    };
    targetFileId?: Ref<number | null>;
}) {
    const { currentItemIndex } = toRefs(params.navigation);
    const { fillComplete } = toRefs(params.overlay);
    const { isOpen } = toRefs(params.sheet);
    const fileData = ref<File | null>(null);
    const isLoadingFileData = ref(false);
    const lastFetchedFileId = ref<number | null>(null);
    const fetchSequence = ref(0);

    const currentItemId = computed(() => {
        if (params.targetFileId) {
            return params.targetFileId.value;
        }

        const index = currentItemIndex.value;
        if (index === null || index < 0 || index >= params.items.value.length) {
            return null;
        }
        return params.items.value[index]?.id ?? null;
    });

    async function handleItemSeen(fileId: number): Promise<void> {
        try {
            const { data } = await window.axios.post<{ seen_count: number }>(incrementSeen.url(fileId));

            const item = params.items.value.find((i) => i.id === fileId);
            if (item) {
                item.seen_count = data.seen_count;
            }
        } catch (error) {
            console.error('Failed to increment seen count:', error);
        }
    }

    async function fetchFileData(fileId: number): Promise<void> {
        if (!fileId) return;

        if (lastFetchedFileId.value === fileId && fileData.value) {
            return;
        }

        const sequence = ++fetchSequence.value;

        isLoadingFileData.value = true;
        fileData.value = null;
        try {
            const { data } = await window.axios.get(getFile.url(fileId));

            // Prevent out-of-order updates when navigating quickly.
            if (sequence !== fetchSequence.value) {
                return;
            }

            fileData.value = data.file;
            lastFetchedFileId.value = fileId;
        } catch (error) {
            console.error('Failed to fetch file data:', error);
            if (sequence !== fetchSequence.value) {
                return;
            }

            fileData.value = null;
            lastFetchedFileId.value = null;
        } finally {
            if (sequence === fetchSequence.value) {
                isLoadingFileData.value = false;
            }
        }
    }

    function setFileData(file: File): void {
        fileData.value = file;
        lastFetchedFileId.value = file.id;

        const item = params.items.value.find((candidate) => candidate.id === file.id);
        if (!item) {
            return;
        }

        const spotifyFile = isSpotifyFile(file);
        const refreshedPreview = (spotifyFile ? file.cover_url : null)
            ?? file.preview_url
            ?? file.preview_file_url
            ?? file.poster_url
            ?? file.file_url
            ?? file.url
            ?? item.preview
            ?? item.src;
        const refreshedUrl = spotifyFile
            ? (file.file_url ?? file.disk_url ?? refreshedPreview)
            : (file.file_url ?? file.url ?? item.url ?? item.src);

        item.url = file.url;
        item.original = refreshedUrl;
        item.originalUrl = refreshedUrl;
        item.src = refreshedPreview;
        item.preview = refreshedPreview;
        item.thumbnail = refreshedPreview;
        item.previewed_count = file.previewed_count;
        item.seen_count = file.seen_count;
        item.auto_blacklisted = file.auto_blacklisted;
        item.auto_blacklist_rule = file.auto_blacklist_rule ?? null;
        item.auto_blacklist_containers = file.auto_blacklist_containers ?? [];
        item.blacklisted_at = file.blacklisted_at;
        item.blacklist_rule = file.blacklist_rule ?? null;
        item.downloaded = file.downloaded;
        item.notFound = file.not_found;
        item.source = file.source;
        item.source_id = file.source_id;
        item.spotify_uri = file.spotify_uri ?? null;
        item.referrer_url = file.referrer_url;
        item.mime_type = file.mime_type;
        item.listing_metadata = file.listing_metadata;
        item.detail_metadata = file.detail_metadata;
        item.containers = file.containers ?? item.containers;
        item.source_access = file.source_access ?? null;
        item.capabilities = file.capabilities;
    }

    // Keep sheet data in sync with either navigation or an explicitly pinned grid item.
    // The overlay sets fillComplete=false during transitions, so also react when it toggles back.
    watch(
        [() => currentItemId.value, () => isOpen.value, () => fillComplete.value],
        async ([fileId, open, filled]) => {
            if (!open) {
                fileData.value = null;
                isLoadingFileData.value = false;
                lastFetchedFileId.value = null;
                return;
            }

            if (!fileId) {
                fileData.value = null;
                isLoadingFileData.value = false;
                lastFetchedFileId.value = null;
                return;
            }

            // Avoid showing stale details for the new file id.
            if (lastFetchedFileId.value !== fileId) {
                fileData.value = null;
                isLoadingFileData.value = true;
            }

            if (!filled) {
                return;
            }

            await fetchFileData(fileId);
        },
        { immediate: true }
    );

    return {
        fileData,
        isLoadingFileData,
        fetchFileData,
        setFileData,
        handleItemSeen,
    };
}
