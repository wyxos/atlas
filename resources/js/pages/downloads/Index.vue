<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import ContentLayout from '@/layouts/ContentLayout.vue';
import SectionHeader from '@/components/audio/SectionHeader.vue';
import ScrollableLayout from '@/layouts/ScrollableLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { OTable, OTableColumn } from '@oruga-ui/oruga-next';
import { Button } from '@/components/ui/button';
import { Pause, Play, XCircle, Download as DownloadIcon, Eye, RotateCcw, Trash2 } from 'lucide-vue-next';
import { reactive, ref } from 'vue';
import { useElementSize } from '@vueuse/core';
import { useEchoPublic } from '@laravel/echo-vue';
import * as DownloadsController from '@/actions/App/Http/Controllers/DownloadsController';

interface DownloadItem {
  id: number;
  status: 'queued' | 'in-progress' | 'paused' | 'completed' | 'failed' | 'canceled' | string;
  progress: number;
  bytes_downloaded?: number | null;
  bytes_total?: number | null;
  started_at?: string | null;
  paused_at?: string | null;
  completed_at?: string | null;
  canceled_at?: string | null;
  file: {
    id: number;
    filename: string;
    url?: string | null;
    thumbnail_url?: string | null;
    downloaded?: boolean;
    download_progress?: number;
  };
}

defineProps<{
  downloads: {
    data: DownloadItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}>();

// Dynamic table height (match Files page behavior)
const tableContainerRef = ref<HTMLElement | null>(null);
const { height } = useElementSize(tableContainerRef);
const tableHeight = height;

// Local realtime state keyed by fileId to reflect echo events
const liveProgress = reactive<Record<number, number>>({});
const liveBytes = reactive<Record<number, { downloaded: number; total: number | null }>>({});
const liveStatus = reactive<Record<number, string>>({});

// Debounced reload when an item transitions to a terminal state
const boundaryStatus = new Set(['completed', 'failed', 'canceled']);
let reloadTimer: number | null = null;

useEchoPublic('file-download-progress', '.App\\Events\\FileDownloadProgress', (e: { fileId: number; progress: number; bytesDownloaded?: number | null; bytesTotal?: number | null; status?: string | null }) => {
  if (!e || typeof e.fileId !== 'number') return;
  liveProgress[e.fileId] = e.progress;
  if (typeof e.bytesDownloaded === 'number') {
    liveBytes[e.fileId] = { downloaded: e.bytesDownloaded, total: typeof e.bytesTotal === 'number' ? e.bytesTotal : (liveBytes[e.fileId]?.total ?? null) } as any;
  } else if (typeof e.bytesTotal === 'number') {
    liveBytes[e.fileId] = { downloaded: liveBytes[e.fileId]?.downloaded ?? 0, total: e.bytesTotal } as any;
  }
  if (e.status) {
    liveStatus[e.fileId] = e.status;
    if (boundaryStatus.has(e.status)) {
      if (reloadTimer) {
        clearTimeout(reloadTimer);
      }
      reloadTimer = window.setTimeout(() => {
        reloadTimer = null;
        router.reload({ only: ['downloads'], preserveScroll: true, preserveState: true });
      }, 200);
    }
  }
});

// When new downloads are created elsewhere, reload the list
useEchoPublic('downloads', '.downloads.created', () => {
  router.reload({ only: ['downloads'], preserveScroll: true, preserveState: true });
});

function percentFor(item: DownloadItem): number {
  const p = liveProgress[item.file.id];
  if (typeof p === 'number') return Math.max(0, Math.min(100, p));
  return Math.max(0, Math.min(100, item.progress ?? 0));
}

function formatBytes(bytes?: number | null): string {
  if (!bytes || bytes <= 0) return '—';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}

function effectiveStatus(item: DownloadItem): string {
  const local = liveStatus[item.file.id];
  if (local) return local;
  // infer completed if progress reached 100
  if (percentFor(item) >= 100) return 'completed';
  return item.status;
}

function canPause(item: DownloadItem) {
  const st = effectiveStatus(item);
  return st === 'in-progress' || st === 'queued';
}
function canResume(item: DownloadItem) {
  const st = effectiveStatus(item);
  return st === 'paused' || st === 'failed';
}
function canCancel(item: DownloadItem) {
  const st = effectiveStatus(item);
  return st !== 'completed' && st !== 'canceled';
}

function sizeFor(item: DownloadItem): { downloaded: number | null; total: number | null } {
  const live = liveBytes[item.file.id];
  if (live) return { downloaded: live.downloaded ?? null, total: live.total ?? null };
  return { downloaded: (item as any).bytes_downloaded ?? null, total: (item as any).bytes_total ?? null };
}

async function pause(item: DownloadItem) {
  if (!canPause(item)) return;
  const action = DownloadsController.pause({ download: item.id });
  await router.post(action.url, {}, { preserveScroll: true, preserveState: true });
}

async function resume(item: DownloadItem) {
  if (!canResume(item)) return;
  const action = DownloadsController.resume({ download: item.id });
  await router.post(action.url, {}, { preserveScroll: true, preserveState: true });
}

async function cancel(item: DownloadItem) {
  if (!canCancel(item)) return;
  if (!confirm('Cancel this download?')) return;
  const action = DownloadsController.cancel({ download: item.id });
  await router.post(action.url, {}, { preserveScroll: true, preserveState: true });
}

async function retry(item: DownloadItem) {
  const action = DownloadsController.retry({ download: item.id });
  await router.post(action.url, {}, { preserveScroll: true, preserveState: true });
}

async function deleteEntry(item: DownloadItem) {
  if (!confirm('Delete this download entry? This will not remove the file entry.')) return;
  const action = DownloadsController.destroy({ download: item.id });
  await router.delete(action.url, { preserveScroll: true, preserveState: true });
}

async function deleteWithFile(item: DownloadItem) {
  if (!confirm('Delete this download and remove the file from disk?')) return;
  const action = DownloadsController.destroyWithFile({ download: item.id });
  await router.delete(action.url, { preserveScroll: true, preserveState: true });
}
</script>

<template>
  <Head title="Downloads" />
  <AppLayout :breadcrumbs="[{ title: 'Downloads', href: DownloadsController.index().url }]">
    <ContentLayout>
      <SectionHeader title="Downloads" :icon="DownloadIcon" />

      <ScrollableLayout>
        <div ref="tableContainerRef" class="h-full">
        <OTable class="h-full" :data="downloads.data" hoverable striped :paginated="false" :sticky-header="true" :height="tableHeight">
          <OTableColumn field="thumb" label="" v-slot="{ row }">
            <div class="px-2 py-1">
              <a
                v-if="row.file?.url"
                :href="row.file.url"
                target="_blank"
                rel="noopener noreferrer"
                class="block h-[40px] w-[40px] overflow-hidden rounded bg-muted/50 transition-all hover:ring-2 hover:ring-primary/50"
              >
                <img v-if="row.file.thumbnail_url" :src="row.file.thumbnail_url" :alt="row.file.filename" class="h-full w-full object-cover" />
                <div v-else class="h-full w-full bg-muted/50" />
              </a>
              <div v-else class="h-[40px] w-[40px] rounded bg-muted/50" />
            </div>
          </OTableColumn>

          <OTableColumn field="filename" label="File" v-slot="{ row }">
            <div class="px-1 text-sm">
              <template v-if="row.file?.url">
                <a :href="row.file.url" target="_blank" rel="noopener noreferrer" class="text-primary-foreground underline underline-offset-2 hover:opacity-80">
                  {{ row.file.filename }}
                </a>
              </template>
              <template v-else>
                {{ row.file?.filename }}
              </template>
            </div>
          </OTableColumn>

          <OTableColumn field="status" label="Status" v-slot="{ row }">
            <div class="px-1 text-xs capitalize">{{ effectiveStatus(row).replace('-', ' ') }}</div>
          </OTableColumn>

          <OTableColumn field="progress" label="Progress" v-slot="{ row }">
            <div class="flex items-center gap-2 px-1">
              <div class="relative h-2 w-40 rounded bg-muted">
                <div class="absolute left-0 top-0 h-2 rounded bg-primary transition-all" :style="{ width: percentFor(row) + '%' }" />
              </div>
              <div class="text-xs text-muted-foreground">{{ percentFor(row) }}%</div>
            </div>
          </OTableColumn>

          <OTableColumn field="bytes" label="Size" v-slot="{ row }">
            <div class="px-1 text-xs">
              <template v-if="sizeFor(row).downloaded !== null || sizeFor(row).total !== null">
                {{ formatBytes(sizeFor(row).downloaded ?? 0) }} / {{ formatBytes(sizeFor(row).total ?? 0) }}
              </template>
              <template v-else>
                —
              </template>
            </div>
          </OTableColumn>

          <OTableColumn field="actions" label="Actions" v-slot="{ row }">
            <div class="flex items-center gap-1 px-1">
              <Button
                v-if="row.file?.url"
                as="a"
                :href="row.file.url"
                target="_blank"
                rel="noopener noreferrer"
                variant="ghost"
                size="icon"
                class="h-8 w-8"
                title="View file"
              >
                <Eye :size="16" />
              </Button>
              <Button v-if="canPause(row)" @click="pause(row)" variant="ghost" size="icon" class="h-8 w-8" title="Pause">
                <Pause :size="16" />
              </Button>
              <Button v-if="canResume(row)" @click="resume(row)" variant="ghost" size="icon" class="h-8 w-8" title="Resume">
                <Play :size="16" />
              </Button>
              <Button v-if="canCancel(row)" @click="cancel(row)" variant="ghost" size="icon" class="h-8 w-8 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-950/50 dark:hover:text-red-400" title="Cancel">
                <XCircle :size="16" />
              </Button>
              <Button @click="retry(row)" variant="ghost" size="icon" class="h-8 w-8" title="Retry">
                <RotateCcw :size="16" />
              </Button>
              <Button @click="deleteEntry(row)" variant="ghost" size="icon" class="h-8 w-8" title="Delete entry">
                <Trash2 :size="16" />
              </Button>
              <Button @click="deleteWithFile(row)" variant="ghost" size="icon" class="h-8 w-8 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-950/50 dark:hover:text-red-400" title="Delete and remove file">
                <Trash2 :size="16" />
              </Button>
            </div>
          </OTableColumn>

          <template #empty>
            <div class="px-4 py-6 text-center text-sm text-muted-foreground">No downloads yet.</div>
          </template>
        </OTable>
        </div>
      </ScrollableLayout>

      <!-- Simple pagination -->
      <div class="mt-4 flex items-center justify-between">
        <div class="text-sm text-muted-foreground">Page {{ downloads.current_page }} of {{ downloads.last_page }} — {{ downloads.total }} total</div>
        <div class="flex items-center gap-2">
          <Link
            :href="DownloadsController.index({ mergeQuery: { page: Math.max(downloads.current_page - 1, 1) } }).url"
            class="rounded-md border border-border px-3 py-1 text-sm hover:bg-accent"
            :class="{ 'pointer-events-none opacity-50': downloads.current_page <= 1 }"
          >
            Previous
          </Link>
          <Link
            :href="DownloadsController.index({ mergeQuery: { page: Math.min(downloads.current_page + 1, downloads.last_page) } }).url"
            class="rounded-md border border-border px-3 py-1 text-sm hover:bg-accent"
            :class="{ 'pointer-events-none opacity-50': downloads.current_page >= downloads.last_page }"
          >
            Next
          </Link>
        </div>
      </div>
    </ContentLayout>
  </AppLayout>
</template>
