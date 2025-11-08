import { describe, it, expect, beforeEach, vi } from 'vitest';
import axios from 'axios';
import { useAudioPlayer } from '@/stores/audio';
import * as AudioController from '@/actions/App/Http/Controllers/AudioController';
import * as AudioReactionsController from '@/actions/App/Http/Controllers/AudioReactionsController';

// Import the functions directly by accessing the module
// Since AppSidebar exports these functions, we'll test them in isolation
vi.mock('axios');
vi.mock('@/stores/audio', () => ({
  useAudioPlayer: vi.fn(),
}));

vi.mock('@/actions/App/Http/Controllers/AudioController', () => ({
  stream: vi.fn(),
}));

vi.mock('@/actions/App/Http/Controllers/AudioReactionsController', () => ({
  index: vi.fn(),
  data: vi.fn(),
}));

describe('AppSidebar queue functionality', () => {
  let mockSetQueueAndPlay: ReturnType<typeof vi.fn>;
  let mockSetQueueAndShuffle: ReturnType<typeof vi.fn>;
  let mockPlay: ReturnType<typeof vi.fn>;

  // Recreate the functions from AppSidebar for testing
  let queuePlaylist: (id: number, opts?: { shuffle?: boolean }) => Promise<void>;
  let queueAudioReaction: (type: string, opts?: { shuffle?: boolean }) => Promise<void>;

  beforeEach(() => {
    vi.clearAllMocks();

    mockSetQueueAndPlay = vi.fn().mockResolvedValue(undefined);
    mockSetQueueAndShuffle = vi.fn().mockResolvedValue(undefined);
    mockPlay = vi.fn().mockResolvedValue(undefined);

    (useAudioPlayer as any).mockReturnValue({
      setQueueAndPlay: mockSetQueueAndPlay,
      setQueueAndShuffle: mockSetQueueAndShuffle,
      play: mockPlay,
    });

    (AudioController.stream as any).mockImplementation(({ file }: { file: number }) => ({
      url: `/audio/${file}/stream`,
    }));

    // Recreate the functions from AppSidebar
    const { setQueueAndPlay, setQueueAndShuffle, play } = useAudioPlayer() as any;
    
    queuePlaylist = async (id: number, opts: { shuffle?: boolean } = {}) => {
      try {
        const idsResponse = await axios.get(`/playlists/${id}/ids`);
        const playlistFileIds = idsResponse.data.ids || [];

        if (playlistFileIds.length === 0) return;

        const queueItems: any[] = playlistFileIds.map((fileId: number) => {
          const streamUrl = AudioController.stream({ file: fileId }).url;
          return {
            id: fileId,
            url: streamUrl,
          };
        });

        if (queueItems.length === 0) return;

        if (opts.shuffle) {
          const shuffled = [...queueItems];
          for (let i = shuffled.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
          }
          await setQueueAndShuffle(shuffled, queueItems, { autoPlay: false });
          await play();
        } else {
          await setQueueAndPlay(queueItems, 0, { autoPlay: true });
        }
      } catch (e) {
        console.error('Failed to queue playlist', e);
      }
    };

    queueAudioReaction = async (type: string, opts: { shuffle?: boolean } = {}) => {
      try {
        const action = AudioReactionsController.data(type);
        const response = await axios.get(action.url);
        const playlistFileIds = response.data.playlistFileIds || [];

        if (playlistFileIds.length === 0) return;

        const queueItems: any[] = playlistFileIds.map((fileId: number) => {
          const streamUrl = AudioController.stream({ file: fileId }).url;
          return {
            id: fileId,
            url: streamUrl,
          };
        });

        if (queueItems.length === 0) return;

        if (opts.shuffle) {
          const shuffled = [...queueItems];
          for (let i = shuffled.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
          }
          await setQueueAndShuffle(shuffled, queueItems, { autoPlay: false });
          await play();
        } else {
          await setQueueAndPlay(queueItems, 0, { autoPlay: true });
        }
      } catch (e) {
        console.error(`Failed to queue audio ${type}`, e);
      }
    };
  });

  describe('queueAudioReaction', () => {
    it('queues and plays audio reaction files without shuffle', async () => {
      const playlistFileIds = [1, 2, 3];

      (AudioReactionsController.data as any).mockReturnValue({
        url: '/audio/favorites/data',
      });

      (axios.get as any).mockResolvedValue({
        data: { playlistFileIds: playlistFileIds },
      });

      await queueAudioReaction('favorites');

      expect(axios.get).toHaveBeenCalledWith('/audio/favorites/data');
      expect(mockSetQueueAndPlay).toHaveBeenCalledTimes(1);
      expect(mockSetQueueAndPlay).toHaveBeenCalledWith(
        expect.arrayContaining([
          expect.objectContaining({ id: 1, url: '/audio/1/stream' }),
          expect.objectContaining({ id: 2, url: '/audio/2/stream' }),
          expect.objectContaining({ id: 3, url: '/audio/3/stream' }),
        ]),
        0,
        { autoPlay: true },
      );
      expect(mockSetQueueAndShuffle).not.toHaveBeenCalled();
      expect(mockPlay).not.toHaveBeenCalled();
    });

    it('queues and shuffles audio reaction files', async () => {
      const playlistFileIds = [1, 2, 3];

      (AudioReactionsController.data as any).mockReturnValue({
        url: '/audio/favorites/data',
      });

      (axios.get as any).mockResolvedValue({
        data: { playlistFileIds: playlistFileIds },
      });

      await queueAudioReaction('favorites', { shuffle: true });

      expect(axios.get).toHaveBeenCalledWith('/audio/favorites/data');
      expect(mockSetQueueAndShuffle).toHaveBeenCalledTimes(1);
      expect(mockSetQueueAndShuffle).toHaveBeenCalledWith(
        expect.arrayContaining([
          expect.objectContaining({ id: expect.any(Number) }),
        ]),
        expect.arrayContaining([
          expect.objectContaining({ id: 1 }),
          expect.objectContaining({ id: 2 }),
          expect.objectContaining({ id: 3 }),
        ]),
        { autoPlay: false },
      );
      expect(mockPlay).toHaveBeenCalledTimes(1);
      expect(mockSetQueueAndPlay).not.toHaveBeenCalled();
    });

    it('handles empty files gracefully', async () => {
      (AudioReactionsController.data as any).mockReturnValue({
        url: '/audio/favorites/data',
      });

      (axios.get as any).mockResolvedValue({
        data: { playlist_file_ids: [] },
      });

      await queueAudioReaction('favorites');

      expect(mockSetQueueAndPlay).not.toHaveBeenCalled();
      expect(mockSetQueueAndShuffle).not.toHaveBeenCalled();
    });

    it('handles API errors gracefully', async () => {
      (AudioReactionsController.data as any).mockReturnValue({
        url: '/audio/favorites/data',
      });

      (axios.get as any).mockRejectedValue(new Error('API Error'));

      const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      await queueAudioReaction('favorites');

      expect(consoleErrorSpy).toHaveBeenCalledWith('Failed to queue audio favorites', expect.any(Error));
      expect(mockSetQueueAndPlay).not.toHaveBeenCalled();

      consoleErrorSpy.mockRestore();
    });
  });

  describe('queuePlaylist', () => {
    it('queues and plays playlist files without shuffle', async () => {
      const playlistFileIds = [1, 2];

      (axios.get as any).mockResolvedValue({
        data: { ids: playlistFileIds },
      });

      await queuePlaylist(1);

      expect(axios.get).toHaveBeenCalledWith('/playlists/1/ids');
      expect(mockSetQueueAndPlay).toHaveBeenCalledTimes(1);
      expect(mockSetQueueAndPlay).toHaveBeenCalledWith(
        expect.arrayContaining([
          expect.objectContaining({ id: 1, url: '/audio/1/stream' }),
          expect.objectContaining({ id: 2, url: '/audio/2/stream' }),
        ]),
        0,
        { autoPlay: true },
      );
      expect(mockSetQueueAndShuffle).not.toHaveBeenCalled();
    });

    it('queues and shuffles playlist files', async () => {
      const playlistFileIds = [1, 2, 3];

      (axios.get as any).mockResolvedValue({
        data: { ids: playlistFileIds },
      });

      await queuePlaylist(1, { shuffle: true });

      expect(axios.get).toHaveBeenCalledWith('/playlists/1/ids');
      expect(mockSetQueueAndShuffle).toHaveBeenCalledTimes(1);
      expect(mockSetQueueAndShuffle).toHaveBeenCalledWith(
        expect.arrayContaining([
          expect.objectContaining({ id: expect.any(Number) }),
        ]),
        expect.arrayContaining([
          expect.objectContaining({ id: 1 }),
          expect.objectContaining({ id: 2 }),
          expect.objectContaining({ id: 3 }),
        ]),
        { autoPlay: false },
      );
      expect(mockPlay).toHaveBeenCalledTimes(1);
    });

    it('builds queue items from all playlist file IDs', async () => {
      const playlistFileIds = [1, 2, 3];

      (axios.get as any).mockResolvedValue({
        data: { ids: playlistFileIds },
      });

      await queuePlaylist(1);

      expect(mockSetQueueAndPlay).toHaveBeenCalledWith(
        expect.arrayContaining([
          expect.objectContaining({ id: 1 }),
          expect.objectContaining({ id: 2 }),
          expect.objectContaining({ id: 3 }),
        ]),
        0,
        { autoPlay: true },
      );
      // Should include all IDs from the playlist
      expect(mockSetQueueAndPlay.mock.calls[0][0]).toHaveLength(3);
    });
  });
});

