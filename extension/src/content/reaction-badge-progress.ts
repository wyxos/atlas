export function resolveReactionBadgeProgressState(options: {
    progressPercent: number | null;
    transferStatus: string | null;
    downloadedAt: string | null;
}): { progressDisplayValue: number; progressColor: string } {
    const progressDisplayValue = (() => {
        if (options.progressPercent !== null) {
            return Math.max(0, Math.min(100, Math.round(options.progressPercent)));
        }

        if (options.transferStatus === 'completed' || options.downloadedAt !== null) {
            return 100;
        }

        return 0;
    })();

    const progressColor = options.transferStatus === 'completed' || options.downloadedAt !== null
        ? '#22c55e'
        : '#14b8a6';

    return { progressDisplayValue, progressColor };
}
