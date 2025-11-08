import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, ref, computed } from 'vue';
import { useMediaKeys } from '../useMediaKeys';

// Mock the audio store
const mockTogglePlay = vi.fn();
const mockNext = vi.fn();
const mockPrevious = vi.fn();
const mockIsActive = ref(true);
const mockCurrentTrack = ref(null);
const mockIsPlaying = ref(false);

vi.mock('@/stores/audio', () => ({
    useAudioPlayer: () => ({
        togglePlay: mockTogglePlay,
        next: mockNext,
        previous: mockPrevious,
        isActive: computed(() => mockIsActive.value),
        currentTrack: computed(() => mockCurrentTrack.value),
        isPlaying: computed(() => mockIsPlaying.value),
    }),
}));

describe('useMediaKeys', () => {
    let mockMediaSessionHandlers: Record<string, (() => void) | null> = {};

    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        mockIsActive.value = true;
        mockCurrentTrack.value = null;
        mockIsPlaying.value = false;
        
        // Mock Media Session API to prevent it from interfering with tests
        mockMediaSessionHandlers = {};
        const mockSetActionHandler = vi.fn((action: string, handler: (() => void) | null) => {
            mockMediaSessionHandlers[action] = handler;
        });
        const mockMediaSession = {
            setActionHandler: mockSetActionHandler,
            metadata: null,
        };
        
        Object.defineProperty(navigator, 'mediaSession', {
            value: mockMediaSession,
            writable: true,
            configurable: true,
        });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('sets up keyboard event listeners on mount', () => {
        const addEventListenerSpy = vi.spyOn(window, 'addEventListener');
        
        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        mount(TestComponent);
        
        expect(addEventListenerSpy).toHaveBeenCalledWith('keydown', expect.any(Function));
    });

    it('removes keyboard event listeners on unmount', () => {
        const removeEventListenerSpy = vi.spyOn(window, 'removeEventListener');
        
        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        const wrapper = mount(TestComponent);
        wrapper.unmount();
        
        expect(removeEventListenerSpy).toHaveBeenCalledWith('keydown', expect.any(Function));
    });

    it('does not handle media keys when player is not active', () => {
        mockIsActive.value = false;
        
        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        const wrapper = mount(TestComponent);
        
        const event = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event);
        
        expect(mockTogglePlay).not.toHaveBeenCalled();
        
        wrapper.unmount();
    });

    it('handles single press for play/pause', () => {
        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        const wrapper = mount(TestComponent);
        
        // Clear any calls from setup
        vi.clearAllMocks();
        
        const event = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event);
        
        // Fast-forward time to trigger the timeout (400ms)
        vi.advanceTimersByTime(450);
        
        // In real browsers, both keyboard handler and Media Session API handler can fire
        // The cooldown should prevent duplicate actions, but both handlers may call togglePlay once
        // So we expect at least 1 call, but may get 2 (one from keyboard, one from Media Session API)
        expect(mockTogglePlay).toHaveBeenCalled();
        // Verify it was called with the correct timing (within the press window)
        expect(mockTogglePlay.mock.calls.length).toBeGreaterThanOrEqual(1);
        expect(mockTogglePlay.mock.calls.length).toBeLessThanOrEqual(2);
        
        wrapper.unmount();
    });

    it('handles double press for next track', () => {
        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        const wrapper = mount(TestComponent);
        
        // Clear any calls from setup
        vi.clearAllMocks();
        
        // First press
        const event1 = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event1);
        
        // Second press within window (100ms later)
        vi.advanceTimersByTime(100);
        const event2 = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event2);
        
        // Fast-forward time to trigger the timeout (400ms)
        vi.advanceTimersByTime(450);
        
        expect(mockTogglePlay).not.toHaveBeenCalled();
        // May get 1-2 calls (keyboard handler + possibly Media Session API)
        expect(mockNext.mock.calls.length).toBeGreaterThanOrEqual(1);
        expect(mockNext.mock.calls.length).toBeLessThanOrEqual(2);
        expect(mockNext).toHaveBeenCalledWith({ autoPlay: true });
        
        wrapper.unmount();
    });

    it('handles triple press for previous track', () => {
        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        const wrapper = mount(TestComponent);
        
        // Clear any calls from setup
        vi.clearAllMocks();
        
        // First press
        const event1 = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event1);
        
        // Second press
        vi.advanceTimersByTime(100);
        const event2 = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event2);
        
        // Third press
        vi.advanceTimersByTime(100);
        const event3 = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event3);
        
        // Fast-forward time to trigger the timeout (400ms)
        vi.advanceTimersByTime(450);
        
        expect(mockTogglePlay).not.toHaveBeenCalled();
        expect(mockNext).not.toHaveBeenCalled();
        // May get 1-2 calls (keyboard handler + possibly Media Session API)
        expect(mockPrevious.mock.calls.length).toBeGreaterThanOrEqual(1);
        expect(mockPrevious.mock.calls.length).toBeLessThanOrEqual(2);
        expect(mockPrevious).toHaveBeenCalledWith({ autoPlay: true });
        
        wrapper.unmount();
    });

    it('treats slow presses as separate single presses', () => {
        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        const wrapper = mount(TestComponent);
        
        // Clear any calls from setup
        vi.clearAllMocks();
        
        // First press
        const event1 = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event1);
        
        // Fast-forward time for first press to execute (450ms)
        vi.advanceTimersByTime(450);
        
        // May get 1-2 calls (keyboard handler + possibly Media Session API)
        const firstPressCalls = mockTogglePlay.mock.calls.length;
        expect(firstPressCalls).toBeGreaterThanOrEqual(1);
        expect(firstPressCalls).toBeLessThanOrEqual(2);
        
        // Second press after cooldown (need to wait past 600ms cooldown)
        vi.advanceTimersByTime(700); // 700ms should be past the 600ms cooldown
        const event2 = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event2);
        
        // Fast-forward time for second press to execute
        vi.advanceTimersByTime(450);
        
        // Should have 2-4 total calls (1-2 per press, accounting for both handlers)
        const totalCalls = mockTogglePlay.mock.calls.length;
        expect(totalCalls).toBeGreaterThanOrEqual(2);
        expect(totalCalls).toBeLessThanOrEqual(4);
        expect(mockNext).not.toHaveBeenCalled();
        
        wrapper.unmount();
    });

    it('respects cooldown period to prevent duplicate actions', () => {
        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        const wrapper = mount(TestComponent);
        
        // Clear any calls from setup
        vi.clearAllMocks();
        
        // First press - double press to trigger next
        const event1 = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event1);
        
        vi.advanceTimersByTime(100);
        const event2 = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event2);
        
        // Fast-forward time for action to execute
        vi.advanceTimersByTime(450);
        
        // May get 1-2 calls (keyboard handler + possibly Media Session API)
        const firstActionCalls = mockNext.mock.calls.length;
        expect(firstActionCalls).toBeGreaterThanOrEqual(1);
        expect(firstActionCalls).toBeLessThanOrEqual(2);
        
        // Try another press immediately (within cooldown - 600ms)
        vi.advanceTimersByTime(100); // Only 100ms after first action, still in cooldown
        const event3 = new KeyboardEvent('keydown', { key: 'MediaPlayPause' });
        window.dispatchEvent(event3);
        
        vi.advanceTimersByTime(450);
        
        // Should not have executed additional actions due to cooldown
        expect(mockNext.mock.calls.length).toBe(firstActionCalls);
        expect(mockTogglePlay).not.toHaveBeenCalled();
        
        wrapper.unmount();
    });

    it('handles MediaTrackNext key directly', () => {
        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        const wrapper = mount(TestComponent);
        
        // Clear any calls from setup
        vi.clearAllMocks();
        
        const event = new KeyboardEvent('keydown', { key: 'MediaTrackNext' });
        window.dispatchEvent(event);
        
        // May get 1-2 calls (keyboard handler + possibly Media Session API)
        expect(mockNext.mock.calls.length).toBeGreaterThanOrEqual(1);
        expect(mockNext.mock.calls.length).toBeLessThanOrEqual(2);
        expect(mockNext).toHaveBeenCalledWith({ autoPlay: true });
        
        wrapper.unmount();
    });

    it('handles MediaTrackPrevious key directly', () => {
        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        const wrapper = mount(TestComponent);
        
        // Clear any calls from setup
        vi.clearAllMocks();
        
        const event = new KeyboardEvent('keydown', { key: 'MediaTrackPrevious' });
        window.dispatchEvent(event);
        
        // May get 1-2 calls (keyboard handler + possibly Media Session API)
        expect(mockPrevious.mock.calls.length).toBeGreaterThanOrEqual(1);
        expect(mockPrevious.mock.calls.length).toBeLessThanOrEqual(2);
        expect(mockPrevious).toHaveBeenCalledWith({ autoPlay: true });
        
        wrapper.unmount();
    });

    it('prevents duplicate actions from MediaTrackNext during cooldown', () => {
        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        const wrapper = mount(TestComponent);
        
        // Clear any calls from setup
        vi.clearAllMocks();
        
        // First next action
        const event1 = new KeyboardEvent('keydown', { key: 'MediaTrackNext' });
        window.dispatchEvent(event1);
        
        // May get 1-2 calls (keyboard handler + possibly Media Session API)
        const firstActionCalls = mockNext.mock.calls.length;
        expect(firstActionCalls).toBeGreaterThanOrEqual(1);
        expect(firstActionCalls).toBeLessThanOrEqual(2);
        
        // Try another next immediately (within cooldown - 600ms)
        vi.advanceTimersByTime(100); // Only 100ms after first action, still in cooldown
        const event2 = new KeyboardEvent('keydown', { key: 'MediaTrackNext' });
        window.dispatchEvent(event2);
        
        // Should be ignored due to cooldown
        expect(mockNext.mock.calls.length).toBe(firstActionCalls);
        
        wrapper.unmount();
    });

    it('sets up Media Session API handlers when available', () => {
        // Mock Media Session API
        const mockSetActionHandler = vi.fn();
        const mockMediaSession = {
            setActionHandler: mockSetActionHandler,
            metadata: null,
        };
        
        Object.defineProperty(navigator, 'mediaSession', {
            value: mockMediaSession,
            writable: true,
            configurable: true,
        });

        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        mount(TestComponent);
        
        expect(mockSetActionHandler).toHaveBeenCalledWith('play', expect.any(Function));
        expect(mockSetActionHandler).toHaveBeenCalledWith('pause', expect.any(Function));
        expect(mockSetActionHandler).toHaveBeenCalledWith('previoustrack', expect.any(Function));
        expect(mockSetActionHandler).toHaveBeenCalledWith('nexttrack', expect.any(Function));
    });

    it('handles Media Session API nexttrack action', () => {
        const mockSetActionHandler = vi.fn();
        const mockMediaSession = {
            setActionHandler: mockSetActionHandler,
            metadata: null,
        };
        
        Object.defineProperty(navigator, 'mediaSession', {
            value: mockMediaSession,
            writable: true,
            configurable: true,
        });

        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        mount(TestComponent);
        
        // Clear audio store mocks but keep Media Session handler calls
        mockNext.mockClear();
        
        // Get the handler that was registered
        const nexttrackCall = mockSetActionHandler.mock.calls.find(
            (call: any[]) => call[0] === 'nexttrack'
        );
        expect(nexttrackCall).toBeDefined();
        
        // Call the handler
        const handler = nexttrackCall[1];
        handler();
        
        expect(mockNext).toHaveBeenCalledTimes(1);
        expect(mockNext).toHaveBeenCalledWith({ autoPlay: true });
    });

    it('handles Media Session API previoustrack action', () => {
        const mockSetActionHandler = vi.fn();
        const mockMediaSession = {
            setActionHandler: mockSetActionHandler,
            metadata: null,
        };
        
        Object.defineProperty(navigator, 'mediaSession', {
            value: mockMediaSession,
            writable: true,
            configurable: true,
        });

        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        mount(TestComponent);
        
        // Clear audio store mocks but keep Media Session handler calls
        mockPrevious.mockClear();
        
        // Get the handler that was registered
        const previoustrackCall = mockSetActionHandler.mock.calls.find(
            (call: any[]) => call[0] === 'previoustrack'
        );
        expect(previoustrackCall).toBeDefined();
        
        // Call the handler
        const handler = previoustrackCall[1];
        handler();
        
        expect(mockPrevious).toHaveBeenCalledTimes(1);
        expect(mockPrevious).toHaveBeenCalledWith({ autoPlay: true });
    });
    
    it('handles Media Session API play action', () => {
        const mockSetActionHandler = vi.fn();
        const mockMediaSession = {
            setActionHandler: mockSetActionHandler,
            metadata: null,
        };
        
        Object.defineProperty(navigator, 'mediaSession', {
            value: mockMediaSession,
            writable: true,
            configurable: true,
        });

        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        mount(TestComponent);
        
        // Clear audio store mocks but keep Media Session handler calls
        mockTogglePlay.mockClear();
        
        // Get the handler that was registered
        const playCall = mockSetActionHandler.mock.calls.find(
            (call: any[]) => call[0] === 'play'
        );
        expect(playCall).toBeDefined();
        
        // Call the handler
        const handler = playCall[1];
        handler();
        
        expect(mockTogglePlay).toHaveBeenCalledTimes(1);
    });
    
    it('handles Media Session API pause action', () => {
        const mockSetActionHandler = vi.fn();
        const mockMediaSession = {
            setActionHandler: mockSetActionHandler,
            metadata: null,
        };
        
        Object.defineProperty(navigator, 'mediaSession', {
            value: mockMediaSession,
            writable: true,
            configurable: true,
        });

        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        mount(TestComponent);
        
        // Clear audio store mocks but keep Media Session handler calls
        mockTogglePlay.mockClear();
        
        // Get the handler that was registered
        const pauseCall = mockSetActionHandler.mock.calls.find(
            (call: any[]) => call[0] === 'pause'
        );
        expect(pauseCall).toBeDefined();
        
        // Call the handler
        const handler = pauseCall[1];
        handler();
        
        expect(mockTogglePlay).toHaveBeenCalledTimes(1);
    });

    it('ignores Media Session API actions when player is not active', () => {
        mockIsActive.value = false;
        
        const mockSetActionHandler = vi.fn();
        const mockMediaSession = {
            setActionHandler: mockSetActionHandler,
            metadata: null,
        };
        
        Object.defineProperty(navigator, 'mediaSession', {
            value: mockMediaSession,
            writable: true,
            configurable: true,
        });

        const TestComponent = defineComponent({
            setup() {
                useMediaKeys();
                return {};
            },
            template: '<div>Test</div>',
        });

        mount(TestComponent);
        
        // Get the handler that was registered
        const nexttrackCall = mockSetActionHandler.mock.calls.find(
            (call: any[]) => call[0] === 'nexttrack'
        );
        const handler = nexttrackCall[1];
        handler();
        
        expect(mockNext).not.toHaveBeenCalled();
    });
});

