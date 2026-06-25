import { ref, watch, toRefs, computed, type Ref } from 'vue';
import FileSourceMetadataController from '@/actions/App/Http/Controllers/FileSourceMetadataController';
import { incrementSeen, show as getFile } from '@/actions/App/Http/Controllers/FilesController';
import type { FeedItem } from '@/composables/useTabs';
import { resolveFilePreviewUrl } from '@/lib/filePreviewGeneration';
import type { File } from '@/types/file';
import { getMimeTypeCategory } from '@/utils/file';

type FileMediaKind = 'image' | 'video' | 'audio' | 'file';

function isSpotifyFile(file: File): boolean {
    return file.source?.trim().toLowerCase() === 'spotify'
        || file.mime_type?.trim().toLowerCase() === 'audio/spotify'
        || Boolean(file.spotify_uri?.trim());
}

function resolveFileMediaKind(file: File): FileMediaKind {
    const category = getMimeTypeCategory(file.mime_type);

    if (category === 'audio' || category === 'video' || category === 'image') {
        return category;
    }

    return 'file';
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
    const isRefreshingSourceMetadata = ref(false);
    const sourceMetadataRefreshError = ref<string | null>(null);
    const lastFetchedFileId = ref<number | null>(null);
    const lastAutoSourceMetadataRefreshFileId = ref<number | null>(null);
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
            autoRefreshSourceMetadata(data.file);
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

        const mediaKind = resolveFileMediaKind(file);
        const spotifyFile = isSpotifyFile(file);
        const refreshedPreview = resolveFilePreviewUrl(file, mediaKind, item.preview ?? item.src ?? null);
        const refreshedUrl = spotifyFile
            ? (file.file_url ?? file.disk_url ?? refreshedPreview)
            : (file.file_url ?? file.disk_url ?? file.url ?? item.url ?? item.src);

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
        item.preview_generation = file.preview_generation ?? null;
        item.notFound = file.not_found;
        item.source = file.source;
        item.source_id = file.source_id;
        item.spotify_uri = file.spotify_uri ?? null;
        item.referrer_url = file.referrer_url;
        item.mime_type = file.mime_type;
        item.metadata = file.metadata?.payload ?? null;
        item.listing_metadata = file.listing_metadata;
        item.detail_metadata = file.detail_metadata;
        item.containers = file.containers ?? item.containers;
        item.source_access = file.source_access ?? null;
        item.capabilities = file.capabilities;
    }

    async function refreshSourceMetadata(fileId: number, options: { automatic?: boolean } = {}): Promise<File | null> {
        if (!fileId || isRefreshingSourceMetadata.value) {
            return null;
        }

        isRefreshingSourceMetadata.value = true;
        sourceMetadataRefreshError.value = null;

        try {
            const { data } = await window.axios.post<{ file?: File; message?: string }>(
                FileSourceMetadataController.url({ file: fileId, target: 'detail' })
            );
            const refreshedFile = data.file ?? null;
            if (refreshedFile) {
                setFileData(refreshedFile);
            }

            return refreshedFile;
        } catch (error) {
            const message = responseMessage(error) ?? 'Failed to fetch source metadata.';
            const status = responseStatus(error);
            if (!options.automatic || status !== 'unsupported_provider') {
                sourceMetadataRefreshError.value = message;
                console.error('Failed to refresh source metadata:', error);
            }

            return null;
        } finally {
            isRefreshingSourceMetadata.value = false;
        }
    }

    function autoRefreshSourceMetadata(file: File | null): void {
        if (!canRefreshSourceMetadata(file) || lastAutoSourceMetadataRefreshFileId.value === file.id) {
            return;
        }

        lastAutoSourceMetadataRefreshFileId.value = file.id;
        void refreshSourceMetadata(file.id, { automatic: true });
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
                lastAutoSourceMetadataRefreshFileId.value = null;
                sourceMetadataRefreshError.value = null;
                return;
            }

            if (!fileId) {
                fileData.value = null;
                isLoadingFileData.value = false;
                lastFetchedFileId.value = null;
                lastAutoSourceMetadataRefreshFileId.value = null;
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
        isRefreshingSourceMetadata,
        sourceMetadataRefreshError,
        fetchFileData,
        setFileData,
        refreshSourceMetadata,
        handleItemSeen,
    };
}

function canRefreshSourceMetadata(file: File | null): file is File {
    return file?.capabilities?.restore_detail_metadata === true;
}

function responseMessage(error: unknown): string | null {
    const response = (error as { response?: { data?: { message?: unknown } } })?.response;
    const message = response?.data?.message;

    return typeof message === 'string' && message.trim() !== '' ? message : null;
}

function responseStatus(error: unknown): string | null {
    const response = (error as { response?: { data?: { status?: unknown } } })?.response;
    const status = response?.data?.status;

    return typeof status === 'string' && status.trim() !== '' ? status : null;
}
