import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useSpotifyPlaybackNotifications } from './useSpotifyPlaybackNotifications';

const mockToast = {
    dismiss: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
};

vi.mock('@/components/ui/toast/use-toast', () => ({
    useToast: () => mockToast,
}));

describe('useSpotifyPlaybackNotifications', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows and dismisses bounded Spotify recovery feedback', () => {
        const { notifySpotifyRecoveryStateChange } = useSpotifyPlaybackNotifications();

        notifySpotifyRecoveryStateChange(true);
        notifySpotifyRecoveryStateChange(false);

        expect(mockToast.info).toHaveBeenCalledWith('Reconnecting to Spotify…', {
            id: 'spotify-playback-recovery',
            description: 'Restoring the browser player after this tab was idle.',
        });
        expect(mockToast.dismiss).toHaveBeenCalledWith('spotify-playback-recovery');
    });

    it('shows retry guidance when Spotify recovery fails', () => {
        const { notifySpotifyPlaybackError } = useSpotifyPlaybackNotifications();

        notifySpotifyPlaybackError();

        expect(mockToast.error).toHaveBeenCalledWith('Spotify playback is unavailable.', {
            id: 'spotify-playback-recovery',
            description: 'Press play to try reconnecting the browser player.',
            duration: 8000,
        });
    });

    it('preserves the Spotify authentication guidance', () => {
        const { notifySpotifyAuthenticationError } = useSpotifyPlaybackNotifications();

        notifySpotifyAuthenticationError('');

        expect(mockToast.error).toHaveBeenCalledWith('Spotify is not connected for this account.', {
            id: 'spotify-playback-authentication-error',
            description: 'Connect or refresh Spotify in Settings, then try playback again.',
            duration: 8000,
        });
    });
});
