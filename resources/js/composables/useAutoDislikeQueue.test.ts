import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useAutoDislikeQueue } from './useAutoDislikeQueue';
import { queueManager } from './useQueue';
import type { FeedItem } from './useTabs';

function createItem(id: number): FeedItem {
    return {
        id,
        width: 500,
        height: 500,
        page: 1,
        key: `1-${id}`,
        index: id - 1,
        src: `https://example.com/preview${id}.jpg`,
        preview: `https://example.com/preview${id}.jpg`,
        type: 'image',
    } as FeedItem;
}

function createSubject() {
    return useAutoDislikeQueue({
        items: ref<FeedItem[]>([]),
        masonry: ref(null),
        isLocal: ref(false),
    });
}

function getCountdownItem(fileId: number) {
    return queueManager.collection.getAll().find((item) => item.id === `auto-dislike-${fileId}`);
}

describe('useAutoDislikeQueue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        queueManager.collection.reset();
    });

    afterEach(() => {
        queueManager.collection.reset();
        vi.useRealTimers();
    });

    it('pauses existing auto-dislike countdowns while the container drawer is open', () => {
        const autoDislikeQueue = createSubject();

        autoDislikeQueue.startAutoDislikeCountdown(101, createItem(101));
        expect(getCountdownItem(101)?.isPaused).toBe(false);

        autoDislikeQueue.freezeAutoDislikeOnly('container-drawer');
        expect(getCountdownItem(101)?.isPaused).toBe(true);

        autoDislikeQueue.unfreezeAutoDislikeOnly('container-drawer');
        vi.advanceTimersByTime(1999);
        expect(getCountdownItem(101)?.isPaused).toBe(true);

        vi.advanceTimersByTime(1);
        expect(getCountdownItem(101)?.isPaused).toBe(false);
    });

    it('starts new auto-dislike countdowns paused while the container drawer remains open', () => {
        const autoDislikeQueue = createSubject();

        autoDislikeQueue.freezeAutoDislikeOnly('container-drawer');
        autoDislikeQueue.startAutoDislikeCountdown(202, createItem(202));

        expect(getCountdownItem(202)?.isPaused).toBe(true);
    });

    it('keeps countdowns paused until every pause source has released them', () => {
        const autoDislikeQueue = createSubject();

        autoDislikeQueue.startAutoDislikeCountdown(303, createItem(303));
        autoDislikeQueue.freezeAutoDislikeOnly('file-viewer');
        autoDislikeQueue.freezeAutoDislikeOnly('container-drawer');

        autoDislikeQueue.unfreezeAutoDislikeOnly('container-drawer');
        vi.advanceTimersByTime(2000);
        expect(getCountdownItem(303)?.isPaused).toBe(true);

        autoDislikeQueue.unfreezeAutoDislikeOnly('file-viewer');
        vi.advanceTimersByTime(2000);
        expect(getCountdownItem(303)?.isPaused).toBe(false);
    });
});
