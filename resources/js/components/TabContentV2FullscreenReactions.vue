<script setup lang="ts">
import type { VibeViewerItem } from '@wyxos/vibe';
import { Pause, Play } from 'lucide-vue-next';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';
import type { FeedItem } from '@/composables/useTabs';
import { isSpotifyFeedAudio } from '@/lib/tabContentV2';
import type { ReactionType } from '@/types/reaction';
import FileReactions from './FileReactions.vue';

const props = defineProps<{
    item: VibeViewerItem;
    index: number;
    total: number;
    canToggleBlacklist?: (item: FeedItem) => boolean;
    handleBlacklist: (item: VibeViewerItem) => void | Promise<void>;
    handleReaction: (item: VibeViewerItem, type: ReactionType) => void | Promise<void>;
    isRemovingItemFromTab?: (item: FeedItem) => boolean;
    removeItemFromTab?: (item: FeedItem) => void | Promise<void>;
}>();

const audioPlayer = useGlobalAudioPlayer();

function getFeedItem(): FeedItem | null {
    return (props.item.feedItem as FeedItem | undefined) ?? null;
}

function normalizeText(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function canPlaySpotifyItem(): boolean {
    const feedItem = getFeedItem();

    return Boolean(feedItem && isSpotifyFeedAudio(feedItem) && normalizeText(feedItem.spotify_uri));
}

function isCurrentSpotifyItemPlaying(): boolean {
    const feedItem = getFeedItem();

    return Boolean(feedItem
        && audioPlayer.currentTrackId.value === feedItem.id
        && audioPlayer.isPlaying.value);
}

function spotifyTrack(feedItem: FeedItem): Omit<AudioPlayerTrack, 'playbackUrl'> {
    return {
        id: feedItem.id,
        title: normalizeText(feedItem.title) ?? normalizeText(feedItem.filename) ?? 'Spotify track',
        source: feedItem.source ?? null,
        sourceId: feedItem.source_id ?? null,
        spotifyUri: normalizeText(feedItem.spotify_uri),
        artists: '',
        album: normalizeText(feedItem.description) ?? '',
        coverUrl: normalizeText(feedItem.preview) ?? normalizeText(feedItem.src) ?? normalizeText(feedItem.thumbnail),
        duration: '0:00',
        durationSeconds: null,
        reaction: feedItem.reaction ?? null,
        blacklistedAt: feedItem.blacklisted_at ?? null,
        previewedCount: feedItem.previewed_count ?? 0,
        seenCount: feedItem.seen_count ?? 0,
    };
}

function toggleSpotifyPlayback(): void {
    const feedItem = getFeedItem();
    if (!feedItem || !canPlaySpotifyItem()) {
        return;
    }

    if (audioPlayer.currentTrackId.value === feedItem.id && audioPlayer.isPlaying.value) {
        audioPlayer.pause();
        return;
    }

    audioPlayer.queueAndPlay([spotifyTrack(feedItem)], feedItem.id, { queueLabel: 'Browse' });
}

function isRemovingFromTab(): boolean {
    const feedItem = getFeedItem();

    return feedItem ? props.isRemovingItemFromTab?.(feedItem) ?? false : false;
}

function removeFromTab(): void {
    const feedItem = getFeedItem();

    if (feedItem) {
        void props.removeItemFromTab?.(feedItem);
    }
}

function shouldAllowBlacklistToggle(): boolean {
    const feedItem = getFeedItem();

    return feedItem ? props.canToggleBlacklist?.(feedItem) ?? false : false;
}
</script>

<template>
    <div
        data-testid="browse-fullscreen-reactions"
        class="flex justify-center gap-2"
    >
        <button
            v-if="canPlaySpotifyItem()"
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center border border-twilight-indigo-500 bg-prussian-blue-900/70 text-twilight-indigo-100 shadow-[0_12px_40px_-24px_rgba(0,0,0,0.95)] transition hover:border-smart-blue-400 hover:bg-prussian-blue-800 hover:text-white"
            :aria-label="isCurrentSpotifyItemPlaying() ? 'Pause Spotify track' : 'Play Spotify track'"
            :title="isCurrentSpotifyItemPlaying() ? 'Pause Spotify track' : 'Play Spotify track'"
            @click.stop="toggleSpotifyPlayback"
        >
            <Pause v-if="isCurrentSpotifyItemPlaying()" :size="16" />
            <Play v-else :size="16" />
        </button>
        <FileReactions
            :file-id="getFeedItem()?.id"
            :reaction="getFeedItem()?.reaction ?? null"
            :blacklisted-at="getFeedItem()?.blacklisted_at ?? null"
            :allow-blacklist-toggle="shouldAllowBlacklistToggle()"
            :previewed-count="getFeedItem()?.previewed_count ?? 0"
            :viewed-count="getFeedItem()?.seen_count ?? 0"
            :current-index="index"
            :total-items="total"
            :icon-size="16"
            surface="none"
            variant="small"
            :show-remove="Boolean(removeItemFromTab && getFeedItem())"
            :removing="isRemovingFromTab()"
            @reaction="(type) => handleReaction(item, type)"
            @blacklist="() => handleBlacklist(item)"
            @remove="removeFromTab"
        />
    </div>
</template>
