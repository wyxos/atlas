<script setup lang="ts">
import * as FileController from '@/actions/App/Http/Controllers/FileController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { files as filesRoute } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { OTable, OTableColumn } from '@oruga-ui/oruga-next';
import { Eye, Files, Trash2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useElementSize } from '@vueuse/core';
import ContentLayout from '@/layouts/ContentLayout.vue';
import SectionHeader from '@/components/audio/SectionHeader.vue';
import ScrollableLayout from '@/layouts/ScrollableLayout.vue';

interface FileItem {
    id: number;
    filename: string;
    mime_type: string | null;
    size: number | null;
    created_at: string;
    url?: string | null;
    thumbnail_url?: string | null;
    has_path?: boolean;
}

const props = defineProps<{
    files: {
        data: FileItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        q: string;
        sort: 'latest' | 'oldest';
        origin: 'local' | 'online' | 'both';
        limit?: number;
    };
}>();

const q = ref(props.filters?.q ?? '');
const sort = ref(props.filters?.sort ?? 'latest');
const origin = ref(props.filters?.origin ?? 'both');
const DEFAULT_LIMIT = 20;
const limit = ref<number>(props.filters?.limit ?? props.files?.per_page ?? DEFAULT_LIMIT);

let applyTimer: ReturnType<typeof setTimeout> | null = null;

watch(
    () => props.filters,
    (filters) => {
        if (!filters) return;

        const nextQ = filters.q ?? '';
        if (q.value !== nextQ) {
            q.value = nextQ;
        }

        const nextSort = filters.sort ?? 'latest';
        if (sort.value !== nextSort) {
            sort.value = nextSort;
        }

        const nextOrigin = filters.origin ?? 'both';
        if (origin.value !== nextOrigin) {
            origin.value = nextOrigin;
        }

        const nextLimit = filters.limit ?? props.files?.per_page ?? DEFAULT_LIMIT;
        if (limit.value !== nextLimit) {
            limit.value = nextLimit;
        }
    },
    { deep: true }
);

const scheduleApply = () => {
    if (applyTimer) {
        clearTimeout(applyTimer);
    }

    applyTimer = setTimeout(() => {
        const data: Record<string, string | number> = { page: 1 };

        if (q.value && q.value.trim()) data.q = q.value.trim();
        if (sort.value !== 'latest') data.sort = sort.value;
        if (origin.value !== 'both') data.origin = origin.value;
        if (limit.value !== DEFAULT_LIMIT) data.limit = limit.value;

        router.get(
            FileController.index().url,
            data,
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            }
        );
    }, 250);
};

watch([q, sort, origin, limit], scheduleApply);

onBeforeUnmount(() => {
    if (applyTimer) {
        clearTimeout(applyTimer);
    }
});

const hasFilters = computed(
    () =>
        !!(q.value && q.value.trim()) ||
        sort.value !== 'latest' ||
        origin.value !== 'both' ||
        limit.value !== DEFAULT_LIMIT
);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Files', href: filesRoute().url }];
// Dynamic table height based on container
const tableContainerRef = ref<HTMLElement | null>(null);
const { height } = useElementSize(tableContainerRef);
const tableHeight = height;

function formatSize(bytes?: number | null): string {
    if (!bytes) return '—';
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${sizes[i]}`;
}

function deleteFile(fileId: number) {
    if (!confirm('Are you sure you want to delete this file? This action cannot be undone.')) {
        return;
    }

    const action = FileController.destroy({ file: fileId });
    router.delete(
        action.url,
        {
            preserveScroll: true,
            onSuccess: () => {
                // File list will be refreshed automatically
            },
            onError: (errors) => {
                console.error('Failed to delete file:', errors);
                alert('Failed to delete file. Please try again.');
            },
        },
    );
}
</script>

<template>
    <Head title="Files" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <ContentLayout>
            <SectionHeader title="Files" :icon="Files"></SectionHeader>
            <form :action="FileController.index().url" method="get" class="mb-4 flex flex-wrap items-start gap-3">
                <div class="grid gap-2">
                    <Label class="text-xs text-muted-foreground">Search</Label>
                    <Input id="q" name="q" type="search" v-model="q" placeholder="Filename or text..." />
                </div>
                <div class="grid gap-2">
                    <Label class="text-xs text-muted-foreground">Origin</Label>
                    <div class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                        <button
                            type="button"
                            @click="origin = 'both'"
                            :class="[
                                    'flex items-center rounded-md px-3 py-1.5 text-sm transition-colors',
                                    origin === 'both'
                                        ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                                        : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                                ]"
                        >
                            Both
                        </button>
                        <button
                            type="button"
                            @click="origin = 'local'"
                            :class="[
                                    'flex items-center rounded-md px-3 py-1.5 text-sm transition-colors',
                                    origin === 'local'
                                        ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                                        : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                                ]"
                        >
                            Local
                        </button>
                        <button
                            type="button"
                            @click="origin = 'online'"
                            :class="[
                                    'flex items-center rounded-md px-3 py-1.5 text-sm transition-colors',
                                    origin === 'online'
                                        ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                                        : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                                ]"
                        >
                            Online
                        </button>
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label class="text-xs text-muted-foreground">Sort</Label>
                    <div class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                        <button
                            type="button"
                            @click="sort = 'latest'"
                            :class="[
                                    'flex items-center rounded-md px-3 py-1.5 text-sm transition-colors',
                                    sort === 'latest'
                                        ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                                        : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                                ]"
                        >
                            Latest
                        </button>
                        <button
                            type="button"
                            @click="sort = 'oldest'"
                            :class="[
                                    'flex items-center rounded-md px-3 py-1.5 text-sm transition-colors',
                                    sort === 'oldest'
                                        ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                                        : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                                ]"
                        >
                            Oldest
                        </button>
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label class="text-xs text-muted-foreground">Per page</Label>
                    <select
                        class="h-9 rounded-md border px-2 text-sm dark:bg-neutral-900"
                        name="limit"
                        v-model.number="limit"
                    >
                        <option :value="20">20</option>
                        <option :value="40">40</option>
                        <option :value="60">60</option>
                        <option :value="100">100</option>
                        <option :value="200">200</option>
                    </select>
                </div>
                <div class="ml-auto">
                    <Button v-if="hasFilters" as="a" :href="FileController.index().url">Clear</Button>
                </div>
            </form>

            <ScrollableLayout>
                <div ref="tableContainerRef" class="h-full">
                    <OTable class="h-full" :data="files.data" hoverable striped :paginated="false" :sticky-header="true" :height="tableHeight">
                    <OTableColumn field="thumbnail" label="" v-slot="props">
                        <div class="px-1">
                            <a
                                v-if="props.row.url"
                                :href="props.row.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="block h-[50px] w-[50px] overflow-hidden rounded bg-muted/50 transition-all hover:ring-2 hover:ring-primary/50"
                            >
                                <img
                                    v-if="props.row.thumbnail_url"
                                    :src="props.row.thumbnail_url"
                                    :alt="props.row.filename"
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                />
                                <div v-else class="h-full w-full bg-muted/50" />
                            </a>
                            <div v-else class="h-[50px] w-[50px] rounded bg-muted/50" />
                        </div>
                    </OTableColumn>
                    <OTableColumn field="id" label="ID" v-slot="props">
                        <span class="px-1 text-sm">{{ props.row.id }}</span>
                    </OTableColumn>
                    <OTableColumn field="filename" label="Filename" v-slot="props">
                        <template v-if="props.row.url">
                            <a
                                class="px-1 text-sm text-primary-foreground underline underline-offset-2 hover:opacity-80"
                                :href="props.row.url"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ props.row.filename }}
                            </a>
                        </template>
                        <template v-else>
                            <span class="px-1 text-sm">{{ props.row.filename }}</span>
                        </template>
                    </OTableColumn>
                    <OTableColumn field="mime_type" label="MIME" v-slot="props">
                        <span class="px-1 text-sm">{{ props.row.mime_type ?? '—' }}</span>
                    </OTableColumn>
                    <OTableColumn field="size" label="Size" v-slot="props">
                        <span class="px-1 text-sm">{{ formatSize(props.row.size) }}</span>
                    </OTableColumn>
                    <OTableColumn field="created_at" label="Created" v-slot="props">
                        <span class="px-1 text-sm">{{ new Date(props.row.created_at).toLocaleString() }}</span>
                    </OTableColumn>
                    <OTableColumn field="actions" label="Actions" v-slot="props">
                        <div class="flex items-center gap-1 px-1">
                            <Button
                                v-if="props.row.url"
                                as="a"
                                :href="props.row.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8"
                                title="View file"
                            >
                                <Eye :size="16" />
                            </Button>
                            <Button
                                v-if="props.row.has_path"
                                @click="deleteFile(props.row.id)"
                                variant="ghost"
                                size="icon"
                                class="h-8 w-8 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-950/50 dark:hover:text-red-400"
                                title="Delete file"
                            >
                                <Trash2 :size="16" />
                            </Button>
                        </div>
                    </OTableColumn>

                    <template #empty>
                        <div class="px-4 py-6 text-center text-sm text-muted-foreground">No files found.</div>
                    </template>
                </OTable>
                </div>
            </ScrollableLayout>

            <!-- Simple pagination -->
            <div class="mt-4 flex items-center justify-between">
                <div class="text-sm text-muted-foreground">Page {{ files.current_page }} of {{ files.last_page }} — {{ files.total }} total</div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="FileController.index({ mergeQuery: { page: Math.max(files.current_page - 1, 1) } }).url"
                        class="rounded-md border border-border px-3 py-1 text-sm hover:bg-accent"
                        :class="{ 'pointer-events-none opacity-50': files.current_page <= 1 }"
                    >
                        Previous
                    </Link>
                    <Link
                        :href="FileController.index({ mergeQuery: { page: Math.min(files.current_page + 1, files.last_page) } }).url"
                        class="rounded-md border border-border px-3 py-1 text-sm hover:bg-accent"
                        :class="{ 'pointer-events-none opacity-50': files.current_page >= files.last_page }"
                    >
                        Next
                    </Link>
                </div>
            </div>
        </ContentLayout>
    </AppLayout>
</template>
