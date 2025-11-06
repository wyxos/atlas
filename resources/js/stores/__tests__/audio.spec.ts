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

function buildTrack(id: number) {
  return {
    id,
    url: `${audioServerUrl}/audio-${id}.mp3`,
    title: `Track ${id}`,
  };
}

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
});

