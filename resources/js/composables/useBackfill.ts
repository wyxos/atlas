import { reactive } from 'vue';

export type BackfillState = {
    active: boolean;
    fetched: number;
    target: number;
    calls: number;
    // normal fill wait (between successive successful calls)
    waiting: boolean;
    waitTotalMs: number;
    waitRemainingMs: number;
    // retry wait state
    retryActive: boolean;
    retryAttempt: number;
    retryMax: number;
    retryWaitTotalMs: number;
    retryWaitRemainingMs: number;
};

export function useBackfill() {
    // Backfill progress state driven by Masonry events
    const backfill = reactive<BackfillState>({
        active: false,
        fetched: 0,
        target: 0,
        calls: 0,
        // normal fill wait (between successive successful calls)
        waiting: false,
        waitTotalMs: 0,
        waitRemainingMs: 0,
        // retry wait state
        retryActive: false,
        retryAttempt: 0,
        retryMax: 3,
        retryWaitTotalMs: 0,
        retryWaitRemainingMs: 0,
    });

    // Backfill event handlers
    function onBackfillStart(payload: { target: number; fetched: number; calls: number; currentPage: any; nextPage: any }): void {
        backfill.active = true;
        backfill.target = payload.target;
        backfill.fetched = payload.fetched;
        backfill.calls = payload.calls;
        backfill.waiting = false;
    }

    function onBackfillTick(payload: { fetched: number; target: number; calls: number; remainingMs: number; totalMs: number; currentPage: any; nextPage: any }): void {
        backfill.active = true;
        backfill.fetched = payload.fetched;
        backfill.target = payload.target;
        backfill.calls = payload.calls;
        backfill.waiting = true;
        backfill.waitRemainingMs = payload.remainingMs;
        backfill.waitTotalMs = payload.totalMs;
    }

    function onBackfillStop(payload: { fetched: number; calls: number; cancelled?: boolean; currentPage: any; nextPage: any }): void {
        backfill.active = false;
        backfill.waiting = false;
        backfill.fetched = payload.fetched;
        backfill.calls = payload.calls;
        backfill.waitRemainingMs = 0;
        backfill.waitTotalMs = 0;
    }

    function onBackfillRetryStart(payload: { attempt: number; max: number; totalMs: number }): void {
        backfill.retryActive = true;
        backfill.retryAttempt = payload.attempt;
        backfill.retryMax = payload.max;
        backfill.retryWaitTotalMs = payload.totalMs;
        backfill.retryWaitRemainingMs = payload.totalMs;
    }

    function onBackfillRetryTick(payload: { attempt: number; remainingMs: number; totalMs: number }): void {
        backfill.retryActive = true;
        backfill.retryAttempt = payload.attempt;
        backfill.retryWaitRemainingMs = payload.remainingMs;
        backfill.retryWaitTotalMs = payload.totalMs;
    }

    function onBackfillRetryStop(): void {
        backfill.retryActive = false;
        backfill.retryAttempt = 0;
        backfill.retryWaitTotalMs = 0;
        backfill.retryWaitRemainingMs = 0;
    }

    return {
        backfill,
        onBackfillStart,
        onBackfillTick,
        onBackfillStop,
        onBackfillRetryStart,
        onBackfillRetryTick,
        onBackfillRetryStop,
    };
}

