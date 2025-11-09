import BrowseController from '@/actions/App/Http/Controllers/BrowseController';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { markRaw } from 'vue';
import { enqueueModeration, flushModeration } from '@/lib/moderation'

export function createBrowseGetPage(form: { data: () => Record<string, any>; defaults: (v: any) => void; reset: () => void }) {
    let urlReplaceTimer: any = null;
    let pendingQueryParams: Record<string, any> | null = null;
    function scheduleUrlReplace(params: Record<string, any>) {
        pendingQueryParams = params;
        if (urlReplaceTimer) clearTimeout(urlReplaceTimer);
        urlReplaceTimer = setTimeout(() => {
            try {
                const search = new URLSearchParams(pendingQueryParams || {}).toString();
                router.replace({
                    url: window.location.pathname + '?' + search,
                    preserveState: true,
                    preserveScroll: true,
                });
            } finally {
                urlReplaceTimer = null;
                pendingQueryParams = null;
            }
        }, 500);
    }
    return async function getPage(page: any) {
        const baseParams = { ...form.data() } as Record<string, any>;
        if (baseParams.type === null || baseParams.type === undefined || baseParams.type === '' || baseParams.type === 'all') {
            delete baseParams.type;
        }

        try {
            const res = await axios.get(BrowseController.data().url, { params: { ...baseParams, page } });
            const batch = (res.data?.files || []).map((r: any) => {
                const i: any = { ...r };
                if (i && i.metadata) i.metadata = markRaw(i.metadata);
                if (Array.isArray(i?.containers)) i.containers = markRaw(i.containers);
                if (i && i.listing_metadata) i.listing_metadata = markRaw(i.listing_metadata);
                if (i && i.detail_metadata) i.detail_metadata = markRaw(i.detail_metadata);
                return i;
            });
            const responseFilter = res.data?.filter || {};

            // sync server-provided filter (cursor/state) to the form
            form.defaults(responseFilter);
            form.reset();

            // moderation toast via undo snackbar
            const moderation = res.data?.moderation || {};
            const ids = Array.isArray(moderation?.ids) ? moderation.ids : [];
            const countFromIds = ids.length;
            const count = Number(moderation?.blacklisted_count || 0);
            const showCount = countFromIds || count;
            if (showCount > 0) {
                const previews = Array.isArray(moderation?.previews)
                    ? moderation.previews.map((p: any) => p?.preview || '').filter(Boolean).slice(0, 4)
                    : [];
                const previewTitles = Array.isArray(moderation?.previews)
                    ? moderation.previews.map((p: any) => p?.title || '').filter(Boolean).slice(0, 4)
                    : [];
                // Aggregate to avoid missing toasts during rapid backfills
                enqueueModeration(ids, previews, previewTitles)
                // Fallback: ensure a flush shortly after this response in case backfill-stop isn't triggered
                window.setTimeout(() => { try { flushModeration() } catch {} }, 500)
            }

            // update URL query params (throttled to avoid extra work during backfill)
            scheduleUrlReplace({ ...baseParams, page: responseFilter.next ?? '' });

            return {
                items: batch,
                nextPage: responseFilter.next ?? null,
            };
        } catch (error) {
            throw error;
        }
    };
}
