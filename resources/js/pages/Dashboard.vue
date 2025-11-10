<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import PlaceholderPattern from '../components/PlaceholderPattern.vue';
import * as DashboardController from '@/actions/App/Http/Controllers/DashboardController';
import axios from 'axios';
import { computed, reactive, ref, onMounted } from 'vue';
import { Database, Disc3, Image, PlaySquare, RefreshCw, Trash2, Heart, ThumbsUp, Laugh, ThumbsDown, Loader2 } from 'lucide-vue-next';

type DashboardFileStats = Partial<{
	audioFilesCount: number;
	videoFilesCount: number;
	imageFilesCount: number;
	audioSpaceUsed: number;
	videoSpaceUsed: number;
	imageSpaceUsed: number;
	audioNotFound: number;
	videoNotFound: number;
	imageNotFound: number;
	totalFilesNotFound: number;
	audioWithMetadata: number;
	audioWithoutMetadata: number;
	audioMetadataReviewRequired: number;
	audioMetadataReviewNotRequired: number;
	globalWithMetadata: number;
	globalWithoutMetadata: number;
	globalMetadataReviewRequired: number;
	globalMetadataReviewNotRequired: number;
	audioLoved: number;
	audioLiked: number;
	audioDisliked: number;
	audioLaughedAt: number;
	audioNoRating: number;
	globalLoved: number;
	globalLiked: number;
	globalDisliked: number;
	globalLaughedAt: number;
	globalNoRating: number;
	videoLoved: number;
	videoLiked: number;
	videoDisliked: number;
	videoLaughedAt: number;
	videoNoRating: number;
	imageLoved: number;
	imageLiked: number;
	imageDisliked: number;
	imageLaughedAt: number;
	imageNoRating: number;
	audioFiles: number;
	videoFiles: number;
	imageFiles: number;
	otherFiles: number;
	audioSize: number;
	videoSize: number;
	imageSize: number;
	otherSize: number;
	diskSpaceTotal: number;
	diskSpaceUsed: number;
	diskSpaceFree: number;
	diskSpaceUsedPercent: number;
}>;

const props = defineProps<{
	fileStats?: DashboardFileStats;
}>();

const breadcrumbs: BreadcrumbItem[] = [
	{
		title: 'Dashboard',
		href: dashboard().url,
	},
];

const stats = reactive<DashboardFileStats>({ ...(props.fileStats ?? {}) });
const initialLoaded = Object.keys(stats).length > 0;
const loading = ref(!initialLoaded);

function formatSize(bytes?: number): string {
	if (!bytes || bytes <= 0) return '0 B';
	const units = ['B', 'KB', 'MB', 'GB', 'TB'];
	const i = Math.floor(Math.log(bytes) / Math.log(1024));
	return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}

const diskUsedPercent = computed<number>(() => {
	const pct = typeof stats.diskSpaceUsedPercent === 'number' ? stats.diskSpaceUsedPercent : 0;
	return Math.max(0, Math.min(100, pct));
});

async function refreshStats() {
	try {
		loading.value = true;
		const res = await axios.get(DashboardController.getFileStatsJson().url);
		Object.assign(stats, res.data || {});
	} catch (e) {
		console.error('Failed to fetch dashboard stats', e);
	} finally {
		loading.value = false;
	}
}

async function clearCacheAndRefresh() {
	try {
		// Clear server cache then refresh
		await axios.post(DashboardController.clearCache().url);
		await refreshStats();
	} catch (e) {
		console.error('Failed to clear cache', e);
		// As a fallback, do a soft refresh via Inertia to keep things consistent
		router.visit(dashboard().url, { preserveScroll: true, preserveState: true });
	}
}
onMounted(() => {
	if (!initialLoaded) {
		refreshStats();
	}
});
</script>

<template>
	<Head title="Dashboard" />

	<AppLayout :breadcrumbs="breadcrumbs">
		<div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
			<!-- Top actions -->
			<div class="flex items-center gap-2">
				<Button size="sm" class="gap-2" @click="refreshStats">
					<RefreshCw :size="16" />
					Refresh
				</Button>
				<Button size="sm" variant="secondary" class="gap-2" @click="clearCacheAndRefresh">
					<Trash2 :size="16" />
					Clear cache
				</Button>
			</div>

			<!-- KPI cards -->
			<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<Card class="border-border">
					<CardHeader class="pb-2">
						<CardDescription class="flex items-center gap-2">
							<Disc3 :size="16" />
							Audio
						</CardDescription>
						<CardTitle class="text-3xl" data-testid="stat-audio-count">
							<Loader2 v-if="loading" class="mr-2 inline-block animate-spin text-muted-foreground" :size="18" />
							<span v-else>{{ stats.audioFilesCount ?? 0 }}</span>
						</CardTitle>
					</CardHeader>
					<CardContent class="text-sm text-muted-foreground">
						<div class="flex items-center justify-between">
							<span>Space</span>
							<span v-if="!loading">{{ formatSize(stats.audioSpaceUsed ?? 0) }}</span>
							<span v-else class="inline-block h-3 w-16 animate-pulse rounded bg-muted/40"></span>
						</div>
						<div class="flex items-center justify-between">
							<span>Not found</span>
							<span v-if="!loading">{{ stats.audioNotFound ?? 0 }}</span>
							<span v-else class="inline-block h-3 w-8 animate-pulse rounded bg-muted/40"></span>
						</div>
					</CardContent>
				</Card>

				<Card class="border-border">
					<CardHeader class="pb-2">
						<CardDescription class="flex items-center gap-2">
							<PlaySquare :size="16" />
							Reels
						</CardDescription>
						<CardTitle class="text-3xl" data-testid="stat-video-count">
							<Loader2 v-if="loading" class="mr-2 inline-block animate-spin text-muted-foreground" :size="18" />
							<span v-else>{{ stats.videoFilesCount ?? 0 }}</span>
						</CardTitle>
					</CardHeader>
					<CardContent class="text-sm text-muted-foreground">
						<div class="flex items-center justify-between">
							<span>Space</span>
							<span v-if="!loading">{{ formatSize(stats.videoSpaceUsed ?? 0) }}</span>
							<span v-else class="inline-block h-3 w-16 animate-pulse rounded bg-muted/40"></span>
						</div>
						<div class="flex items-center justify-between">
							<span>Not found</span>
							<span v-if="!loading">{{ stats.videoNotFound ?? 0 }}</span>
							<span v-else class="inline-block h-3 w-8 animate-pulse rounded bg-muted/40"></span>
						</div>
					</CardContent>
				</Card>

				<Card class="border-border">
					<CardHeader class="pb-2">
						<CardDescription class="flex items-center gap-2">
							<Image :size="16" />
							Photos
						</CardDescription>
						<CardTitle class="text-3xl" data-testid="stat-image-count">
							<Loader2 v-if="loading" class="mr-2 inline-block animate-spin text-muted-foreground" :size="18" />
							<span v-else>{{ stats.imageFilesCount ?? 0 }}</span>
						</CardTitle>
					</CardHeader>
					<CardContent class="text-sm text-muted-foreground">
						<div class="flex items-center justify-between">
							<span>Space</span>
							<span v-if="!loading">{{ formatSize(stats.imageSpaceUsed ?? 0) }}</span>
							<span v-else class="inline-block h-3 w-16 animate-pulse rounded bg-muted/40"></span>
						</div>
						<div class="flex items-center justify-between">
							<span>Not found</span>
							<span v-if="!loading">{{ stats.imageNotFound ?? 0 }}</span>
							<span v-else class="inline-block h-3 w-8 animate-pulse rounded bg-muted/40"></span>
						</div>
					</CardContent>
				</Card>

				<Card class="border-border">
					<CardHeader class="pb-2">
						<CardDescription class="flex items-center gap-2">
							<Database :size="16" />
							Storage
						</CardDescription>
						<CardTitle class="text-3xl">
							<Loader2 v-if="loading" class="mr-2 inline-block animate-spin text-muted-foreground" :size="18" />
							<span v-else>{{ formatSize(stats.diskSpaceUsed ?? 0) }}</span>
						</CardTitle>
					</CardHeader>
					<CardContent>
						<div class="mb-2 flex items-center justify-between text-sm text-muted-foreground">
							<span>Used</span>
							<span v-if="!loading" data-testid="stat-disk-percent">{{ diskUsedPercent.toFixed(1) }}%</span>
							<span v-else class="inline-block h-3 w-10 animate-pulse rounded bg-muted/40"></span>
						</div>
						<div class="h-2 w-full overflow-hidden rounded bg-neutral-200 dark:bg-neutral-800">
							<div
								class="h-full bg-primary transition-[width] duration-500"
								:style="{ width: `${diskUsedPercent}%` }"
							></div>
						</div>
						<div class="mt-2 flex items-center justify-between text-xs text-muted-foreground">
							<span v-if="!loading">Free: {{ formatSize(stats.diskSpaceFree ?? 0) }}</span>
							<span v-else class="inline-block h-3 w-16 animate-pulse rounded bg-muted/40"></span>
							<span v-if="!loading">Total: {{ formatSize(stats.diskSpaceTotal ?? 0) }}</span>
							<span v-else class="inline-block h-3 w-16 animate-pulse rounded bg-muted/40"></span>
						</div>
					</CardContent>
				</Card>
			</div>

			<!-- Global reactions row (lightweight queries) -->
			<div class="grid gap-4 md:grid-cols-4">
				<Card class="border-border">
					<CardHeader class="pb-2">
						<CardDescription class="flex items-center gap-2">
							<Heart :size="16" />
							Loved (all)
						</CardDescription>
						<CardTitle class="text-2xl">
							<Loader2 v-if="loading" class="mr-2 inline-block animate-spin text-muted-foreground" :size="18" />
							<span v-else>{{ stats.globalLoved ?? 0 }}</span>
						</CardTitle>
					</CardHeader>
				</Card>
				<Card class="border-border">
					<CardHeader class="pb-2">
						<CardDescription class="flex items-center gap-2">
							<ThumbsUp :size="16" />
							Liked (all)
						</CardDescription>
						<CardTitle class="text-2xl">
							<Loader2 v-if="loading" class="mr-2 inline-block animate-spin text-muted-foreground" :size="18" />
							<span v-else>{{ stats.globalLiked ?? 0 }}</span>
						</CardTitle>
					</CardHeader>
				</Card>
				<Card class="border-border">
					<CardHeader class="pb-2">
						<CardDescription class="flex items-center gap-2">
							<Laugh :size="16" />
							Funny (all)
						</CardDescription>
						<CardTitle class="text-2xl">
							<Loader2 v-if="loading" class="mr-2 inline-block animate-spin text-muted-foreground" :size="18" />
							<span v-else>{{ stats.globalLaughedAt ?? 0 }}</span>
						</CardTitle>
					</CardHeader>
				</Card>
				<Card class="border-border">
					<CardHeader class="pb-2">
						<CardDescription class="flex items-center gap-2">
							<ThumbsDown :size="16" />
							No rating (all)
						</CardDescription>
						<CardTitle class="text-2xl">
							<Loader2 v-if="loading" class="mr-2 inline-block animate-spin text-muted-foreground" :size="18" />
							<span v-else>{{ stats.globalNoRating ?? 0 }}</span>
						</CardTitle>
					</CardHeader>
				</Card>
			</div>

			<!-- Fallback canvas area (kept for future charts) -->
			<div class="relative min-h-[320px] flex-1 rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
				<PlaceholderPattern />
			</div>
		</div>
	</AppLayout>
	</template>
