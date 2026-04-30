import { computed, nextTick, ref, watch, type ComputedRef, type Ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { FeedItem } from '@/composables/useTabs';
import type { File } from '@/types/file';
import { getMimeTypeCategory } from '@/utils/file';

type SurfaceMode = 'fullscreen' | 'list';

type UseBrowseV2SurfaceRouteSyncOptions = {
    activeIndex: Ref<number>;
    currentVisibleItem: ComputedRef<FeedItem | null>;
    isSessionReady: Ref<boolean>;
    isTabDataLoading: Ref<boolean>;
    loadStandaloneFileItem: (fileId: number) => Promise<FeedItem | null>;
    removedItemIds: Ref<Set<number>>;
    sessionItems: ComputedRef<FeedItem[]>;
    standaloneItem: Ref<FeedItem | null>;
    surfaceMode: Ref<SurfaceMode>;
    tabId: ComputedRef<number | null>;
    visibleItems: ComputedRef<FeedItem[]>;
};

export function buildBrowseV2FilePath(fileId: number): string {
    return `/browse/file/${fileId}`;
}

function resolveFileMediaKind(file: File): FeedItem['media_kind'] {
    const category = getMimeTypeCategory(file.mime_type);

    if (category === 'audio' || category === 'video' || category === 'image') {
        return category;
    }

    return 'file';
}

export function mapBrowseV2FileToFeedItem(file: File): FeedItem {
    const previewUrl = file.preview_file_url
        ?? file.preview_url
        ?? file.poster_url
        ?? file.file_url
        ?? file.disk_url
        ?? file.url
        ?? '';
    const originalUrl = file.file_url
        ?? file.disk_url
        ?? file.url
        ?? previewUrl;
    const mediaKind = resolveFileMediaKind(file);

    return {
        id: file.id,
        width: file.width ?? 1,
        height: file.height ?? 1,
        page: 1,
        key: `browse-file-${file.id}`,
        index: 0,
        src: previewUrl,
        preview: previewUrl || undefined,
        original: originalUrl || undefined,
        originalUrl: originalUrl || undefined,
        thumbnail: previewUrl || undefined,
        url: file.url ?? file.file_url ?? file.disk_url,
        type: mediaKind === 'video' ? 'video' : 'image',
        media_kind: mediaKind,
        reaction: null,
        previewed_count: file.previewed_count,
        seen_count: file.seen_count,
        auto_disliked: file.auto_disliked,
        auto_dislike_rule: file.auto_dislike_rule ?? null,
        blacklisted_at: file.blacklisted_at,
        blacklist_rule: file.blacklist_rule ?? null,
        downloaded: file.downloaded,
        title: file.title ?? file.filename,
        filename: file.filename,
        source: file.source,
        source_id: file.source_id,
        referrer_url: file.referrer_url,
        containers: file.containers ?? [],
        notFound: file.not_found,
    };
}

export function useBrowseV2SurfaceRouteSync(options: UseBrowseV2SurfaceRouteSyncOptions) {
    const route = useRoute();
    const router = useRouter();
    const isApplyingRouteState = ref(false);
    const isClosingFullscreenRoute = ref(false);
    const routeSyncRequestId = ref(0);

    const routeFileId = computed(() => {
        if (route.name !== 'browse-file') {
            return null;
        }

        const raw = Array.isArray(route.params.fileId) ? route.params.fileId[0] : route.params.fileId;
        const parsed = Number(raw);

        return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
    });

    async function syncRouteDrivenSurfaceState(): Promise<void> {
        const fileId = routeFileId.value;
        const requestId = routeSyncRequestId.value + 1;
        routeSyncRequestId.value = requestId;

        if (fileId === null) {
            isApplyingRouteState.value = true;

            try {
                options.standaloneItem.value = null;
                options.surfaceMode.value = 'list';
            } finally {
                await nextTick();
                isClosingFullscreenRoute.value = false;
                isApplyingRouteState.value = false;
            }

            return;
        }

        if (options.isTabDataLoading.value || !options.isSessionReady.value) {
            return;
        }

        await nextTick();

        const shouldOpenStandaloneRoute = options.surfaceMode.value !== 'fullscreen'
            && options.standaloneItem.value === null;

        if (shouldOpenStandaloneRoute) {
            const nextStandaloneItem = options.visibleItems.value.find((item) => item.id === fileId)
                ?? await options.loadStandaloneFileItem(fileId);

            if (routeSyncRequestId.value !== requestId) {
                return;
            }

            if (!nextStandaloneItem) {
                await router.replace('/browse');
                return;
            }

            isApplyingRouteState.value = true;

            try {
                options.standaloneItem.value = nextStandaloneItem;
                options.activeIndex.value = 0;
                options.surfaceMode.value = 'fullscreen';
            } finally {
                await nextTick();
                isApplyingRouteState.value = false;
            }

            return;
        }

        if (options.standaloneItem.value?.id === fileId) {
            isApplyingRouteState.value = true;

            try {
                options.activeIndex.value = 0;
                options.surfaceMode.value = 'fullscreen';
            } finally {
                await nextTick();
                isApplyingRouteState.value = false;
            }

            return;
        }

        const contextualIndex = options.visibleItems.value.findIndex((item) => item.id === fileId);
        if (contextualIndex >= 0) {
            isApplyingRouteState.value = true;

            try {
                options.standaloneItem.value = null;
                options.activeIndex.value = contextualIndex;
                options.surfaceMode.value = 'fullscreen';
            } finally {
                await nextTick();
                isApplyingRouteState.value = false;
            }

            return;
        }

        if (options.surfaceMode.value === 'fullscreen'
            && options.standaloneItem.value === null
            && options.removedItemIds.value.has(fileId)) {
            const nextContextualItemId = options.currentVisibleItem.value?.id ?? null;

            if (nextContextualItemId !== null) {
                await router.replace(buildBrowseV2FilePath(nextContextualItemId));
            }

            return;
        }
        const nextStandaloneItem = await options.loadStandaloneFileItem(fileId);
        if (routeSyncRequestId.value !== requestId) {
            return;
        }

        if (!nextStandaloneItem) {
            await router.replace('/browse');
            return;
        }

        isApplyingRouteState.value = true;

        try {
            options.standaloneItem.value = nextStandaloneItem;
            options.activeIndex.value = 0;
            options.surfaceMode.value = 'fullscreen';
        } finally {
            await nextTick();
            isApplyingRouteState.value = false;
        }
    }

    function handleVibeActiveIndexUpdate(nextIndex: number): void {
        options.activeIndex.value = nextIndex;
    }

    function handleVibeSurfaceModeUpdate(nextMode: SurfaceMode): void {
        const previousMode = options.surfaceMode.value;
        options.surfaceMode.value = nextMode;

        if (isApplyingRouteState.value) {
            return;
        }

        if (nextMode === 'fullscreen') {
            const nextItemId = options.currentVisibleItem.value?.id ?? null;
            if (nextItemId !== null) {
                const navigate = previousMode === 'fullscreen' ? router.replace : router.push;
                void navigate(buildBrowseV2FilePath(nextItemId));
            }

            return;
        }

        if (previousMode === 'fullscreen') {
            isApplyingRouteState.value = true;
            isClosingFullscreenRoute.value = true;
            options.standaloneItem.value = null;
            void router.replace('/browse').finally(async () => {
                await nextTick();
                if (routeFileId.value === null) {
                    isClosingFullscreenRoute.value = false;
                }
                isApplyingRouteState.value = false;
            });
        }
    }

    watch(
        () => options.sessionItems.value.length,
        (count) => {
            if (count === 0) {
                options.activeIndex.value = 0;
                return;
            }

            if (options.activeIndex.value >= count) {
                options.activeIndex.value = count - 1;
            }
        },
        { immediate: true },
    );

    watch(
        [
            routeFileId,
            options.tabId,
            () => options.visibleItems.value.map((item) => item.id).join(','),
            () => options.standaloneItem.value?.id ?? null,
            options.isSessionReady,
            options.isTabDataLoading,
        ],
        () => {
            void syncRouteDrivenSurfaceState();
        },
        { immediate: true },
    );

    watch(
        () => options.currentVisibleItem.value?.id ?? null,
        (nextItemId, previousItemId) => {
            if (nextItemId === null
                || nextItemId === previousItemId
                || options.surfaceMode.value !== 'fullscreen'
                || isApplyingRouteState.value) {
                return;
            }

            if (routeFileId.value === nextItemId) {
                return;
            }

            void router.replace(buildBrowseV2FilePath(nextItemId));
        },
    );

    return {
        handleVibeActiveIndexUpdate,
        handleVibeSurfaceModeUpdate,
        isClosingFullscreenRoute,
    };
}
