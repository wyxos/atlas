import { show as showFile } from '@/actions/App/Http/Controllers/FilesController';
import type { FeedItem } from '@/composables/useTabs';
import { mapBrowseV2FileToFeedItem } from '@/composables/useBrowseV2SurfaceRouteSync';
import type { File } from '@/types/file';

export async function loadBrowseV2StandaloneFileItem(fileId: number): Promise<FeedItem | null> {
    const { data } = await window.axios.get<{ file: File }>(showFile.url(fileId));

    return data?.file ? mapBrowseV2FileToFeedItem(data.file) : null;
}
