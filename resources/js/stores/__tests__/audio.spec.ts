import { beforeEach, afterEach, beforeAll, afterAll, describe, expect, it, vi } from 'vitest';
import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';

type TestAudio = HTMLAudioElement & { emit(eventName: string): void };

const originalAudio = globalThis.Audio;
const originalHtmlAudio = globalThis.HTMLAudioElement;
const originalXmlHttpRequest = globalThis.XMLHttpRequest;

let createdAudios: TestAudio[] = [];
let audioServer: Server | null = null;
let audioServerUrl = '';

const AUDIO_BUFFER = Buffer.from(
  '4944330300000000000F544954320000000C005465737420547261636B0000000000000000000000000000030000000000',
  'hex',
);

beforeAll(async () => {
  audioServer = createServer((req, res) => {
    if (!req.url) {
      res.statusCode = 400;
      res.end();
      return;
    }

    if (req.method === 'GET' && req.url.startsWith('/audio-')) {
      res.statusCode = 200;
      res.setHeader('Content-Type', 'audio/mpeg');
      res.setHeader('Content-Length', AUDIO_BUFFER.length.toString());
      res.write(AUDIO_BUFFER);
      res.end();
      return;
    }

    res.statusCode = 404;
    res.end();
  });

  await new Promise<void>((resolve) => {
    audioServer!.listen(0, '127.0.0.1', () => resolve());
  });

  const address = audioServer.address() as AddressInfo;
  audioServerUrl = `http://127.0.0.1:${address.port}`;

  const BaseXHR = originalXmlHttpRequest;

  class InterceptingXHR extends BaseXHR {
    private intercepted = false;

    open(method: string, url: string | URL, async?: boolean, user?: string | null, password?: string | null): void {
      const normalizedUrl = typeof url === 'string' ? url : url.toString();
      this.intercepted = normalizedUrl.startsWith(audioServerUrl + '/audio-');
      if (this.intercepted) {
        super.open(method, normalizedUrl, async ?? true, user ?? null, password ?? null);
      } else {
        super.open(method, url as any, async ?? true, user ?? null, password ?? null);
      }
    }

    send(body?: Document | XMLHttpRequestBodyInit | null): void {
      if (!this.intercepted) {
        super.send(body as any);

        return;
      }

      setTimeout(() => {
        (this as any).status = 200;
        (this as any).readyState = 4;
        (this as any).response = AUDIO_BUFFER.buffer.slice(0);
        this.onreadystatechange?.(new Event('readystatechange'));
        this.onload?.(new Event('load'));
      }, 5);
    }
  }

  (globalThis as any).XMLHttpRequest = InterceptingXHR;
});

afterAll(async () => {
  (globalThis as any).XMLHttpRequest = originalXmlHttpRequest;
  if (audioServer) {
    await new Promise<void>((resolve, reject) => {
      audioServer!.close((err) => (err ? reject(err) : resolve()));
    });
    audioServer = null;
  }
});

function buildTrack(id: number, withMetadata = false) {
  const track: any = {
    id,
    url: `${audioServerUrl}/audio-${id}.mp3`,
  };
  
  if (withMetadata) {
    track.artists = [{ name: `Artist ${id}` }];
    track.metadata = { payload: { title: `Track ${id}` } };
  }
  
  return track;
}

const axiosPostMock = vi.hoisted(() => vi.fn());
vi.mock('axios', () => ({
  default: {
    post: axiosPostMock,
  },
}));

vi.mock('@/actions/App/Http/Controllers/AudioController', () => ({
  batchDetails: () => ({ url: '/audio/batch-details' }),
}));

async function importStore() {
  const mod = await import('@/stores/audio');
  return mod.useAudioPlayer();
}

async function flushPromises() {
  await new Promise((resolve) => setTimeout(resolve, 0));
  await Promise.resolve();
}

function getAudioInstance(): TestAudio {
  expect(createdAudios.length).toBeGreaterThan(0);
  return createdAudios[0];
}

describe('audio store', () => {
  beforeEach(() => {
    createdAudios = [];
    axiosPostMock.mockClear();
    axiosPostMock.mockImplementation((url: string, data: any) => {
      if (url === '/audio/batch-details' && data?.file_ids) {
        const response: Record<number, any> = {};
        for (const id of data.file_ids) {
          response[id] = {
            id,
            artists: [{ name: `Artist ${id}` }],
            metadata: { payload: { title: `Track ${id}` } },
          };
        }
        return Promise.resolve({ data: response });
      }
      return Promise.reject(new Error(`Unexpected axios.post call: ${url}`));
    });

    const BaseAudio = originalAudio as unknown as { new (): HTMLAudioElement & { _emit(eventName: string): void } };

    class InstrumentedAudio extends BaseAudio {
      constructor() {
        super();
        createdAudios.push(this as TestAudio);
      }

      emit(eventName: string) {
        (this as unknown as { _emit(eventName: string): void })._emit(eventName);
      }
    }

    vi.resetModules();
    (globalThis as any).Audio = InstrumentedAudio;
    (globalThis as any).HTMLAudioElement = InstrumentedAudio;
  });

  afterEach(() => {
    (globalThis as any).Audio = originalAudio;
    (globalThis as any).HTMLAudioElement = originalHtmlAudio;
    vi.restoreAllMocks();
  });

  it('queues tracks and starts playback from the requested index', async () => {
    const store = await importStore();
    const tracks = [buildTrack(1), buildTrack(2), buildTrack(3)];

    await store.setQueueAndPlay(tracks, 1);

    expect(store.queue.value.map((track) => track.id)).toEqual([1, 2, 3]);
    expect(store.currentIndex.value).toBe(1);
    expect(store.currentTrack.value?.id).toBe(2);
    expect(store.isPlaying.value).toBe(true);

    const audio = getAudioInstance();
    expect(audio.src).toBe(tracks[1].url);
    expect(store.duration.value).toBeGreaterThan(0);
  });

  it('advances to the next track and keeps playing when the current track ends', async () => {
    const store = await importStore();
    const tracks = [buildTrack(10), buildTrack(11)];

    await store.setQueueAndPlay(tracks, 0);

    const audio = getAudioInstance();
    expect(store.currentTrack.value?.id).toBe(10);

    audio.emit('ended');
    await flushPromises();
    await flushPromises();

    expect(store.currentIndex.value).toBe(1);
    expect(store.currentTrack.value?.id).toBe(11);
    expect(store.isPlaying.value).toBe(true);
  });

  it('respects the autoPlay option when switching tracks manually', async () => {
    const store = await importStore();
    const tracks = [buildTrack(21), buildTrack(22)];

    await store.setQueueAndPlay(tracks, 0, { autoPlay: false });

    expect(store.isPlaying.value).toBe(false);
    expect(store.currentTrack.value?.id).toBe(21);

    await store.playTrackAtIndex(1);
    expect(store.currentIndex.value).toBe(1);
    expect(store.currentTrack.value?.id).toBe(22);
    expect(store.isPlaying.value).toBe(false);

    await store.playTrackAtIndex(1, { autoPlay: true });
    expect(store.isPlaying.value).toBe(true);
  });

  it('updates current time and volume through the API', async () => {
    const store = await importStore();
    const tracks = [buildTrack(31)];

    await store.setQueueAndPlay(tracks, 0);

    const audio = getAudioInstance();

    store.seekTo(42);
    expect(audio.currentTime).toBe(42);
    audio.emit('timeupdate');
    expect(store.currentTime.value).toBe(42);

    store.setVolume(0.25);
    expect(store.volume.value).toBeCloseTo(0.25);
    expect(audio.volume).toBeCloseTo(0.25);

    store.setVolume(2);
    expect(store.volume.value).toBe(1);
    expect(audio.volume).toBe(1);
  });

  it('loads track metadata for current + next 5 + previous 5 when setting queue', async () => {
    const store = await importStore();
    // Create 15 tracks, start at index 7 (middle)
    // Index 7 = id 8 (0-indexed: index 0 = id 1, index 7 = id 8)
    const tracks = Array.from({ length: 15 }, (_, i) => buildTrack(i + 1));

    await store.setQueueAndPlay(tracks, 7);
    await flushPromises();
    await flushPromises();

    // Should load tracks 3-13 (index 7 ± 5 = indices 2-12, which are ids 3-13)
    expect(axiosPostMock).toHaveBeenCalledTimes(1);
    const call = axiosPostMock.mock.calls[0];
    expect(call[0]).toBe('/audio/batch-details');
    const fileIds = call[1].file_ids as number[];
    expect(fileIds.sort((a, b) => a - b)).toEqual([3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13]);

    // Verify metadata was merged into queue items
    const queue = store.queue.value;
    expect(queue[6].artists?.[0]?.name).toBe('Artist 7');
    expect(queue[6].metadata?.payload?.title).toBe('Track 7');
    expect(queue[6].url).toBe(`${audioServerUrl}/audio-7.mp3`); // URL preserved

    // Verify current track was updated (index 7 = id 8)
    expect(store.currentTrack.value?.artists?.[0]?.name).toBe('Artist 8');
    expect(store.currentTrack.value?.metadata?.payload?.title).toBe('Track 8');
    expect(store.currentTrack.value?.url).toBe(`${audioServerUrl}/audio-8.mp3`);
  });

  it('loads new context metadata when navigating next', async () => {
    const store = await importStore();
    const tracks = Array.from({ length: 20 }, (_, i) => buildTrack(i + 1));

    // Start at index 5, loads context for indices 0-10 (ids 1-11)
    await store.setQueueAndPlay(tracks, 5);
    await flushPromises();
    await flushPromises();

    axiosPostMock.mockClear();

    // Navigate to index 6 (id 7)
    // New context: index 6 ± 5 = indices 1-11 = ids 2-12
    // But ids 2-11 already have metadata from initial load, so only load id 12
    await store.next();
    await flushPromises();
    await flushPromises();

    expect(axiosPostMock).toHaveBeenCalledTimes(1);
    const call = axiosPostMock.mock.calls[0];
    const fileIds = call[1].file_ids as number[];
    expect(fileIds.sort((a, b) => a - b)).toEqual([12]);
  });

  it('loads new context metadata when navigating previous', async () => {
    const store = await importStore();
    const tracks = Array.from({ length: 20 }, (_, i) => buildTrack(i + 1));

    // Start at index 15, loads context for indices 10-19 (ids 11-20)
    await store.setQueueAndPlay(tracks, 15);
    await flushPromises();
    await flushPromises();

    axiosPostMock.mockClear();

    // Navigate to index 14 (id 15)
    // New context: index 14 ± 5 = indices 9-19 = ids 10-20
    // But ids 11-20 already have metadata from initial load, so only load id 10
    await store.previous();
    await flushPromises();
    await flushPromises();

    expect(axiosPostMock).toHaveBeenCalledTimes(1);
    const call = axiosPostMock.mock.calls[0];
    const fileIds = call[1].file_ids as number[];
    expect(fileIds.sort((a, b) => a - b)).toEqual([10]);
  });

  it('loads new context metadata when auto-playing next track', async () => {
    const store = await importStore();
    const tracks = Array.from({ length: 15 }, (_, i) => buildTrack(i + 1));

    // Start at index 0, loads context for indices 0-5 (ids 1-6)
    await store.setQueueAndPlay(tracks, 0);
    await flushPromises();
    await flushPromises();

    axiosPostMock.mockClear();

    // Simulate track ending (auto-plays next track at index 1)
    // New context: index 1 ± 5 = indices 0-6 = ids 1-7
    // But ids 1-6 already have metadata from initial load, so only load id 7
    const audio = getAudioInstance();
    audio.emit('ended');
    await flushPromises();
    await flushPromises();
    await flushPromises();

    expect(axiosPostMock).toHaveBeenCalledTimes(1);
    const call = axiosPostMock.mock.calls[0];
    const fileIds = call[1].file_ids as number[];
    expect(fileIds.sort((a, b) => a - b)).toEqual([7]);
  });

  it('only loads tracks that do not already have metadata', async () => {
    const store = await importStore();
    // Create tracks where some already have metadata
    const tracks = [
      buildTrack(1, true), // Has metadata
      buildTrack(2), // No metadata
      buildTrack(3, true), // Has metadata
      buildTrack(4), // No metadata
      buildTrack(5), // No metadata
      buildTrack(6), // No metadata
      buildTrack(7), // No metadata
      buildTrack(8), // No metadata
    ];

    await store.setQueueAndPlay(tracks, 3);
    await flushPromises();
    await flushPromises();

    // Should only load tracks 2, 4, 5, 6, 7, 8 (skip 1 and 3 which have metadata)
    expect(axiosPostMock).toHaveBeenCalledTimes(1);
    const call = axiosPostMock.mock.calls[0];
    const fileIds = call[1].file_ids as number[];
    expect(fileIds.sort((a, b) => a - b)).toEqual([2, 4, 5, 6, 7, 8]);
  });

  it('preserves URL when merging metadata into queue items', async () => {
    const store = await importStore();
    const tracks = [buildTrack(100)];

    await store.setQueueAndPlay(tracks, 0);
    await flushPromises();
    await flushPromises();

    // Verify URL is preserved after metadata merge
    const queue = store.queue.value;
    expect(queue[0].url).toBe(`${audioServerUrl}/audio-100.mp3`);
    expect(queue[0].artists?.[0]?.name).toBe('Artist 100');
    expect(queue[0].metadata?.payload?.title).toBe('Track 100');

    // Verify current track URL is preserved
    expect(store.currentTrack.value?.url).toBe(`${audioServerUrl}/audio-100.mp3`);
    expect(store.currentTrack.value?.artists?.[0]?.name).toBe('Artist 100');
  });
});

