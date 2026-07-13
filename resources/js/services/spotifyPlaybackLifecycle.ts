type SpotifyPlaybackLifecycleOptions = {
    onPageHide: () => void;
    onTabHidden: () => void;
};

export function registerSpotifyPlaybackLifecycle(options: SpotifyPlaybackLifecycleOptions): () => void {
    function handleVisibilityChange(): void {
        if (document.visibilityState === 'hidden') {
            options.onTabHidden();
        }
    }

    window.addEventListener('pagehide', options.onPageHide);
    document.addEventListener('visibilitychange', handleVisibilityChange);

    return (): void => {
        window.removeEventListener('pagehide', options.onPageHide);
        document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
}
