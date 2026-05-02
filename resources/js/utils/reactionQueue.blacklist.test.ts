import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { queueBlacklist, queueBatchBlacklist } from './reactionQueue';
import { queueManager } from '@/composables/useQueue';

const { mockToast, mockReactionCallback, mockBatchReactionCallback, mockBatchBlacklistCallback, mockUpdateReactionState } = vi.hoisted(() => {
    const toast = vi.fn();
    toast.dismiss = vi.fn();
    toast.error = vi.fn();
    toast.success = vi.fn();
    toast.info = vi.fn();
    toast.warning = vi.fn();

    return {
        mockToast: toast,
        mockReactionCallback: vi.fn().mockResolvedValue(undefined),
        mockBatchReactionCallback: vi.fn().mockResolvedValue(undefined),
        mockBatchBlacklistCallback: vi.fn().mockResolvedValue([]),
        mockUpdateReactionState: vi.fn(),
    };
});

vi.mock('@/components/ui/toast/use-toast', () => ({
    useToast: () => mockToast,
    default: {},
}));

vi.mock('./reactions', () => ({
    createReactionCallback: () => mockReactionCallback,
    createBatchReactionCallback: () => mockBatchReactionCallback,
    createBatchBlacklistCallback: () => mockBatchBlacklistCallback,
}));

vi.mock('@/utils/reactionStateUpdater', () => ({
    default: mockUpdateReactionState,
}));

describe('reactionQueue blacklist', () => {
    let queue: typeof queueManager;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        mockBatchBlacklistCallback.mockResolvedValue([]);
        queue = queueManager;
        queue.collection.reset();
    });

    afterEach(() => {
        vi.useRealTimers();
        queue.collection.reset();
    });

    it('adds blacklist to queue with undoable reaction metadata', () => {
        const restoreCallback = vi.fn();

        queueBlacklist(123, 'https://example.com/thumb.jpg', restoreCallback);

        expect(queue.collection.has('blacklist-123')).toBe(true);
        expect(queue.collection.getAll()[0]?.metadata).toMatchObject({
            fileId: 123,
            reactionType: 'blacklist',
            restoreCallback,
        });
        expect(mockToast.mock.calls[0][0].props).toMatchObject({
            fileId: 123,
            reactionType: 'blacklist',
        });
    });

    it('executes blacklist callback and success handler when countdown expires', async () => {
        const onSuccess = vi.fn();
        const results = [{ id: 123, blacklisted_at: '2026-05-01T00:00:00Z', previewed_count: 99999 }];
        mockBatchBlacklistCallback.mockResolvedValueOnce(results);

        queueBlacklist(123, undefined, undefined, undefined, { onSuccess });

        vi.advanceTimersByTime(5000);
        await vi.runAllTimersAsync();

        expect(mockBatchBlacklistCallback).toHaveBeenCalledWith([123]);
        expect(onSuccess).toHaveBeenCalledWith(results);
        expect(mockToast.dismiss).toHaveBeenCalledWith('blacklist-123');
    });

    it('restores queued blacklist state when execution fails', async () => {
        const restoreCallback = vi.fn().mockResolvedValue(undefined);
        mockBatchBlacklistCallback.mockRejectedValueOnce(new Error('API Error'));
        vi.spyOn(console, 'error').mockImplementation(() => {});

        queueBlacklist(123, undefined, restoreCallback);

        vi.advanceTimersByTime(5000);
        await vi.runAllTimersAsync();

        expect(restoreCallback).toHaveBeenCalledTimes(1);
        expect(mockToast.error).toHaveBeenCalledWith(
            'Failed to blacklist file',
            expect.objectContaining({ id: 'blacklist-123-error' }),
        );
    });

    it('adds batch blacklist to queue and uses blacklist toast metadata', () => {
        const fileIds = [123, 456];
        const previews = [{ fileId: 123, thumbnail: 'thumb1.jpg' }, { fileId: 456, thumbnail: 'thumb2.jpg' }];

        queueBatchBlacklist(fileIds, previews);

        const item = queue.collection.getAll()[0];
        expect(item?.id).toMatch(/^batch-blacklist-123-2-\d+$/);
        expect(item?.metadata).toMatchObject({
            fileIds,
            reactionType: 'blacklist',
            previews,
        });
        expect(mockToast.mock.calls[0][0].props).toMatchObject({
            reactionType: 'blacklist',
            previews,
            totalCount: 2,
        });
    });

    it('executes batch blacklist callback and success handler when countdown expires', async () => {
        const fileIds = [123, 456];
        const results = [
            { id: 123, blacklisted_at: '2026-05-01T00:00:00Z' },
            { id: 456, blacklisted_at: '2026-05-01T00:00:00Z' },
        ];
        const onSuccess = vi.fn();
        mockBatchBlacklistCallback.mockResolvedValueOnce(results);

        queueBatchBlacklist(fileIds, [], undefined, undefined, { onSuccess });
        const queueId = queue.collection.getAll()[0]?.id;

        vi.advanceTimersByTime(5000);
        await vi.runAllTimersAsync();

        expect(mockBatchBlacklistCallback).toHaveBeenCalledWith(fileIds);
        expect(onSuccess).toHaveBeenCalledWith(results);
        expect(mockToast.dismiss).toHaveBeenCalledWith(queueId);
    });

    it('restores queued batch blacklist state when execution fails', async () => {
        const restoreCallback = vi.fn().mockResolvedValue(undefined);
        mockBatchBlacklistCallback.mockRejectedValueOnce(new Error('API Error'));
        vi.spyOn(console, 'error').mockImplementation(() => {});

        queueBatchBlacklist([123, 456], [], restoreCallback);
        const queueId = queue.collection.getAll()[0]?.id;

        vi.advanceTimersByTime(5000);
        await vi.runAllTimersAsync();

        expect(restoreCallback).toHaveBeenCalledTimes(1);
        expect(mockToast.error).toHaveBeenCalledWith(
            'Failed to blacklist files',
            expect.objectContaining({ id: `${queueId}-error` }),
        );
    });
});
