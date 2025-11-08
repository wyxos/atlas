import { onMounted, onBeforeUnmount, watch } from 'vue';
import { useAudioPlayer } from '@/stores/audio';

/**
 * Composable to handle media keys and headset button controls
 * 
 * Supports:
 * - Single press: Play/Pause
 * - Double press: Next track
 * - Triple press: Previous track
 */
export function useMediaKeys() {
    const { togglePlay, next, previous, isActive, currentTrack, isPlaying } = useAudioPlayer();

    let pressTimeout: number | null = null;
    let pressCount = 0;
    let lastPressTime = 0;
    let actionExecutedAt = 0; // Timestamp when action was last executed
    const PRESS_WINDOW = 400; // ms window to detect multiple presses
    const ACTION_COOLDOWN = 600; // ms cooldown after executing an action (longer to prevent slow double-press issues)

    function handleMediaKeyPress() {
        const now = Date.now();
        
        // If an action was just executed, ignore presses during cooldown
        if (now - actionExecutedAt < ACTION_COOLDOWN) {
            return;
        }

        // Reset count if too much time has passed since last press
        if (now - lastPressTime > PRESS_WINDOW) {
            pressCount = 0;
        }

        pressCount++;
        lastPressTime = now;

        // Clear existing timeout
        if (pressTimeout) {
            clearTimeout(pressTimeout);
        }

        // Wait to see if there are more presses
        pressTimeout = window.setTimeout(() => {
            // Prevent duplicate execution - check again in case action was executed during timeout
            if (now - actionExecutedAt < ACTION_COOLDOWN) {
                pressCount = 0;
                return;
            }

            // Mark action as executed immediately to prevent race conditions
            actionExecutedAt = Date.now();

            if (pressCount === 1) {
                // Single press: Play/Pause
                togglePlay();
            } else if (pressCount === 2) {
                // Double press: Next track
                next({ autoPlay: true });
            } else if (pressCount >= 3) {
                // Triple or more presses: Previous track
                previous({ autoPlay: true });
            }
            
            pressCount = 0;
        }, PRESS_WINDOW);
    }

    function handleKeyDown(event: KeyboardEvent) {
        // Only handle if player is active
        if (!isActive.value) {
            return;
        }

        // Prevent default behavior for media keys
        const key = event.key;
        if (key === 'MediaPlayPause' || key === 'PlayPause') {
            event.preventDefault();
            handleMediaKeyPress();
        } else if (key === 'MediaTrackNext' || key === 'NextTrack') {
            // Only handle dedicated next button if we're not in cooldown
            const now = Date.now();
            if (now - actionExecutedAt < ACTION_COOLDOWN) {
                event.preventDefault();
                return;
            }
            event.preventDefault();
            actionExecutedAt = now;
            next({ autoPlay: true });
        } else if (key === 'MediaTrackPrevious' || key === 'PreviousTrack') {
            // Only handle dedicated previous button if we're not in cooldown
            const now = Date.now();
            if (now - actionExecutedAt < ACTION_COOLDOWN) {
                event.preventDefault();
                return;
            }
            event.preventDefault();
            actionExecutedAt = now;
            previous({ autoPlay: true });
        }
    }

    function updateMediaSessionMetadata() {
        if (typeof navigator === 'undefined' || !('mediaSession' in navigator)) {
            return;
        }

        const mediaSession = (navigator as any).mediaSession;
        const track = currentTrack.value;

        if (!track || !isActive.value) {
            try {
                mediaSession.metadata = null;
            } catch (error) {
                // Ignore errors when clearing metadata
            }
            return;
        }

        try {
            const trackAny = track as any;
            const title = trackAny.metadata?.payload?.title || trackAny.title || 'Untitled';
            const artist = trackAny.artists?.[0]?.name || 'Unknown Artist';
            const album = trackAny.albums?.[0]?.name || '';
            const artwork: MediaImage[] = [];

            // Add album cover if available
            if (trackAny.albums?.[0]?.covers?.[0]) {
                const cover = trackAny.albums[0].covers[0];
                artwork.push({
                    src: cover.url || cover.path || '',
                    sizes: '512x512',
                    type: 'image/jpeg',
                });
            } else if (trackAny.covers?.[0]) {
                const cover = trackAny.covers[0];
                artwork.push({
                    src: cover.url || cover.path || '',
                    sizes: '512x512',
                    type: 'image/jpeg',
                });
            }

            mediaSession.metadata = new (window as any).MediaMetadata({
                title,
                artist,
                album,
                artwork,
            });
        } catch (error) {
            console.debug('Failed to update Media Session metadata:', error);
        }
    }

    function setupMediaSession() {
        if (typeof navigator === 'undefined' || !('mediaSession' in navigator)) {
            return;
        }

        const mediaSession = (navigator as any).mediaSession;

        // Set up action handlers
        try {
            mediaSession.setActionHandler('play', () => {
                // If we're in cooldown, ignore
                const now = Date.now();
                if (now - actionExecutedAt < ACTION_COOLDOWN) {
                    return;
                }
                if (isActive.value) {
                    actionExecutedAt = now;
                    togglePlay();
                }
            });

            mediaSession.setActionHandler('pause', () => {
                // If we're in cooldown, ignore
                const now = Date.now();
                if (now - actionExecutedAt < ACTION_COOLDOWN) {
                    return;
                }
                if (isActive.value) {
                    actionExecutedAt = now;
                    togglePlay();
                }
            });

            mediaSession.setActionHandler('previoustrack', () => {
                // Ignore if we're in cooldown
                const now = Date.now();
                if (now - actionExecutedAt < ACTION_COOLDOWN) {
                    return;
                }
                if (isActive.value) {
                    actionExecutedAt = now;
                    previous({ autoPlay: true });
                }
            });

            mediaSession.setActionHandler('nexttrack', () => {
                // Ignore if we're in cooldown
                const now = Date.now();
                if (now - actionExecutedAt < ACTION_COOLDOWN) {
                    return;
                }
                if (isActive.value) {
                    actionExecutedAt = now;
                    next({ autoPlay: true });
                }
            });
        } catch (error) {
            // Some browsers may not support all actions
            console.debug('Media Session API not fully supported:', error);
        }

        // Update metadata when track changes
        watch([currentTrack, isActive], () => {
            updateMediaSessionMetadata();
        }, { immediate: true });
    }

    onMounted(() => {
        // Listen for keyboard media keys
        window.addEventListener('keydown', handleKeyDown);

        // Set up Media Session API
        setupMediaSession();
    });

    onBeforeUnmount(() => {
        window.removeEventListener('keydown', handleKeyDown);

        if (pressTimeout) {
            clearTimeout(pressTimeout);
        }
    });
}

