import { useToast } from '@/components/ui/toast/use-toast';

const SPOTIFY_RECOVERY_TOAST_ID = 'spotify-playback-recovery';

export function useSpotifyPlaybackNotifications() {
    const toast = useToast();

    function notifySpotifyAuthenticationError(message: string): void {
        toast.error(message || 'Spotify is not connected for this account.', {
            id: 'spotify-playback-authentication-error',
            description: 'Connect or refresh Spotify in Settings, then try playback again.',
            duration: 8000,
        });
    }

    function notifySpotifyPlaybackError(): void {
        toast.error('Spotify playback is unavailable.', {
            id: SPOTIFY_RECOVERY_TOAST_ID,
            description: 'Press play to try reconnecting the browser player.',
            duration: 8000,
        });
    }

    function notifySpotifyRecoveryStateChange(isRecovering: boolean): void {
        if (!isRecovering) {
            toast.dismiss(SPOTIFY_RECOVERY_TOAST_ID);

            return;
        }

        toast.info('Reconnecting to Spotify…', {
            id: SPOTIFY_RECOVERY_TOAST_ID,
            description: 'Restoring the browser player after Spotify lost the active device.',
        });
    }

    return {
        notifySpotifyAuthenticationError,
        notifySpotifyPlaybackError,
        notifySpotifyRecoveryStateChange,
    };
}
