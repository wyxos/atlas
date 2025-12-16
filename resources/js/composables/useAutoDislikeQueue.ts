import { useCountdownQueue } from './useCountdownQueue';

type OnExpireCallback = (expiredIds: number[]) => void;

/**
 * Composable for managing auto-dislike queue for files.
 * Wraps the generic useCountdownQueue with file-specific typing.
 */
export function useAutoDislikeQueue(onExpire?: OnExpireCallback) {
    return useCountdownQueue<number>(onExpire);
}

