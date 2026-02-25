import { test, expect, chromium, type BrowserContext, type Page } from '@playwright/test';
import http from 'node:http';
import path from 'node:path';
import os from 'node:os';
import fs from 'node:fs/promises';

type AtlasRecord = {
  id: number;
  url: string;
  referrerUrl: string | null;
  previewUrl: string | null;
  downloaded: boolean;
  reactionType: string | null;
  downloadProgress: number;
  downloadedAt: string | null;
};

function stripHash(value: string): string {
  const hashPos = value.indexOf('#');
  return hashPos >= 0 ? value.slice(0, hashPos) : value;
}

function isHashSpecificReferrerLookupKey(value: string): boolean {
  const hashPos = value.indexOf('#');
  if (hashPos < 0) {
    return false;
  }

  const fragment = value.slice(hashPos + 1).toLowerCase();
  return /^image-\d+$/.test(fragment);
}

function createBmp(width: number, height: number): Buffer {
  // 24-bit BMP (BGR), rows padded to 4 bytes.
  const rowSize = Math.ceil((width * 3) / 4) * 4;
  const pixelDataSize = rowSize * height;
  const fileSize = 54 + pixelDataSize;

  const buf = Buffer.alloc(fileSize);
  buf.write('BM', 0, 2, 'ascii');
  buf.writeUInt32LE(fileSize, 2);
  buf.writeUInt32LE(54, 10); // pixel array offset

  // DIB header (BITMAPINFOHEADER)
  buf.writeUInt32LE(40, 14);
  buf.writeInt32LE(width, 18);
  buf.writeInt32LE(height, 22);
  buf.writeUInt16LE(1, 26); // planes
  buf.writeUInt16LE(24, 28); // bits per pixel
  buf.writeUInt32LE(0, 30); // compression (BI_RGB)
  buf.writeUInt32LE(pixelDataSize, 34);
  buf.writeInt32LE(2835, 38); // 72 DPI
  buf.writeInt32LE(2835, 42);
  buf.writeUInt32LE(0, 46);
  buf.writeUInt32LE(0, 50);

  // Simple gradient so preview isn't totally flat.
  let offset = 54;
  for (let y = 0; y < height; y += 1) {
    for (let x = 0; x < width; x += 1) {
      const r = Math.floor((x / Math.max(1, width - 1)) * 255);
      const g = Math.floor((y / Math.max(1, height - 1)) * 255);
      const b = 64;
      buf[offset++] = b;
      buf[offset++] = g;
      buf[offset++] = r;
    }
    // Row padding
    while ((offset - 54) % rowSize !== 0) {
      buf[offset++] = 0;
    }
  }

  return buf;
}

async function startStubAtlasServer() {
  const records: AtlasRecord[] = [];
  let id = 1;

  const findOrCreateRecord = (input: {
    url: string;
    referrerUrl?: string | null;
    previewUrl?: string | null;
  }): { record: AtlasRecord; created: boolean } => {
    const url = input.url.trim();
    const referrerUrl = input.referrerUrl?.trim() || null;
    const previewUrl = input.previewUrl?.trim() || null;
    const existing = records.find((record) => record.url === url && (record.referrerUrl || '') === (referrerUrl || ''));
    if (existing) {
      return { record: existing, created: false };
    }

    const record: AtlasRecord = {
      id: id++,
      url,
      referrerUrl,
      previewUrl,
      downloaded: false,
      reactionType: null,
      downloadProgress: 0,
      downloadedAt: null,
    };
    records.push(record);
    return { record, created: true };
  };

  const scoreRecordMatch = (record: AtlasRecord, lookupUrl: string): [number, number, number, number, number] => {
    const normalizedLookup = stripHash(lookupUrl);
    const recordUrl = record.url.trim();
    const recordReferrer = (record.referrerUrl || '').trim();
    const recordPreview = (record.previewUrl || '').trim();
    const lookupIsHashSpecific = isHashSpecificReferrerLookupKey(lookupUrl);

    const exactUrl = recordUrl !== '' && recordUrl === lookupUrl ? 1 : 0;
    const exactReferrer = recordReferrer !== '' && recordReferrer === lookupUrl ? 1 : 0;
    const exactPreview = recordPreview !== '' && recordPreview === lookupUrl ? 1 : 0;

    const allowNormalized = !lookupIsHashSpecific;
    const normalizedUrl =
      allowNormalized && recordUrl !== '' && stripHash(recordUrl) === normalizedLookup ? 1 : 0;
    const normalizedReferrer =
      allowNormalized && recordReferrer !== '' && stripHash(recordReferrer) === normalizedLookup ? 1 : 0;
    const normalizedPreview =
      allowNormalized && recordPreview !== '' && stripHash(recordPreview) === normalizedLookup ? 1 : 0;

    return [
      exactUrl,
      exactReferrer,
      exactPreview,
      normalizedUrl + normalizedReferrer + normalizedPreview,
      record.id,
    ];
  };

  const findBestRecordForLookup = (lookupUrl: string): AtlasRecord | null => {
    const key = lookupUrl.trim();
    if (!key) {
      return null;
    }

    let best: AtlasRecord | null = null;
    let bestScore: [number, number, number, number, number] = [0, 0, 0, 0, 0];
    const isLexicographicallyGreater = (
      left: [number, number, number, number, number],
      right: [number, number, number, number, number]
    ): boolean => {
      for (let index = 0; index < left.length; index += 1) {
        if (left[index] === right[index]) {
          continue;
        }

        return left[index] > right[index];
      }

      return false;
    };

    for (const record of records) {
      const score = scoreRecordMatch(record, key);
      if (!best || isLexicographicallyGreater(score, bestScore)) {
        best = record;
        bestScore = score;
      }
    }

    if (!best) {
      return null;
    }

    if (bestScore[0] === 0 && bestScore[1] === 0 && bestScore[2] === 0 && bestScore[3] === 0) {
      return null;
    }

    return best;
  };

  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url ?? '/', 'http://127.0.0.1');

    const sendJson = (status: number, body: unknown) => {
      res.statusCode = status;
      res.setHeader('Content-Type', 'application/json');
      res.setHeader('Access-Control-Allow-Origin', '*');
      res.end(JSON.stringify(body));
    };

    if (req.method === 'OPTIONS') {
      res.statusCode = 204;
      res.setHeader('Access-Control-Allow-Origin', '*');
      res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS, GET');
      res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Atlas-Extension-Token, Authorization');
      res.end();
      return;
    }

    if (req.method === 'GET' && url.pathname === '/fixture') {
      res.statusCode = 200;
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.end(`<!doctype html>
<html>
  <head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>Fixture</title></head>
  <body style="margin:0;font-family:system-ui">
    <h1 style="padding:12px">Atlas Extension Fixture</h1>
    <p style="padding:0 12px">Two big images (512x512, 800x450).</p>
    <img src="/fixture/hero.bmp" alt="hero" />
    <img src="/fixture/wide.bmp" alt="wide" />
  </body>
</html>`);
      return;
    }

    if (req.method === 'GET' && url.pathname === '/fixture/scenario1') {
      res.statusCode = 200;
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.end(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Scenario 1</title>
    <style>
      #modal { position: fixed; inset: 0; background: rgba(0,0,0,.7); display: none; align-items: center; justify-content: center; }
      #modal.open { display: flex; }
      #modal-content { background: white; padding: 10px; border-radius: 8px; }
    </style>
  </head>
  <body>
    <a id="s1-link" href="${`/fixture/s1-large.bmp`}">
      <img id="s1-thumb" src="${`/fixture/s1-small.bmp`}" alt="small" />
    </a>
    <div id="modal">
      <div id="modal-content">
        <button id="close-modal">Close</button>
        <img id="s1-modal-image" src="${`/fixture/s1-large.bmp`}" alt="large" />
      </div>
    </div>
    <script>
      const link = document.getElementById('s1-link');
      const modal = document.getElementById('modal');
      const close = document.getElementById('close-modal');
      link.addEventListener('click', (e) => { e.preventDefault(); modal.classList.add('open'); });
      close.addEventListener('click', () => modal.classList.remove('open'));
      modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.remove('open'); });
    </script>
  </body>
</html>`);
      return;
    }

    if (req.method === 'GET' && url.pathname === '/fixture/scenario2') {
      res.statusCode = 200;
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.end(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Scenario 2</title>
    <style>
      #modal { position: fixed; inset: 0; background: rgba(0,0,0,.7); display: none; align-items: center; justify-content: center; }
      #modal.open { display: flex; }
      #modal-content { background: white; padding: 10px; border-radius: 8px; }
    </style>
  </head>
  <body>
    <img id="s2-main" src="${`/fixture/s2-large.bmp`}" alt="large-initial" />
    <button id="s2-open-modal" type="button">Open modal</button>
    <div id="modal">
      <div id="modal-content">
        <button id="close-modal">Close</button>
        <img id="s2-modal-image" src="${`/fixture/s2-small.bmp`}" alt="small-modal" />
      </div>
    </div>
    <script>
      const open = document.getElementById('s2-open-modal');
      const modal = document.getElementById('modal');
      const close = document.getElementById('close-modal');
      open.addEventListener('click', () => { modal.classList.add('open'); });
      close.addEventListener('click', () => modal.classList.remove('open'));
      modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.remove('open'); });
    </script>
  </body>
</html>`);
      return;
    }

    if (req.method === 'GET' && url.pathname === '/fixture/scenario3') {
      res.statusCode = 200;
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.end(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Scenario 3</title>
    <style>
      #modal { position: fixed; inset: 0; background: rgba(0,0,0,.7); display: none; align-items: center; justify-content: center; }
      #modal.open { display: flex; }
      #modal-content { background: white; padding: 10px; border-radius: 8px; min-width: 220px; min-height: 220px; }
    </style>
  </head>
  <body>
    <a id="s3-link" href="${`/fixture/s3-large-modal.bmp`}">
      <img id="s3-main" src="${`/fixture/s3-large-initial.bmp`}" alt="large-initial" />
    </a>
    <div id="modal">
      <div id="modal-content">
        <button id="close-modal">Close</button>
        <div id="s3-slot"></div>
      </div>
    </div>
    <script>
      const link = document.getElementById('s3-link');
      const modal = document.getElementById('modal');
      const close = document.getElementById('close-modal');
      const slot = document.getElementById('s3-slot');
      link.addEventListener('click', (e) => {
        e.preventDefault();
        modal.classList.add('open');
        slot.innerHTML = '';
        setTimeout(() => {
          const img = document.createElement('img');
          img.id = 's3-modal-image';
          img.src = '${`/fixture/s3-large-modal.bmp`}';
          img.alt = 'large-modal';
          slot.appendChild(img);
        }, 120);
      });
      close.addEventListener('click', () => modal.classList.remove('open'));
      modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.remove('open'); });
    </script>
  </body>
</html>`);
      return;
    }

    if (req.method === 'GET' && url.pathname === '/fixture/cross-tab/a') {
      res.statusCode = 200;
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.end(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Cross-tab A</title>
  </head>
  <body style="font-family:system-ui">
    <h1>Cross-tab Tab A</h1>
    <img id="xt-main" src="/fixture/xt-main.bmp" alt="main" width="860" height="860" />
  </body>
</html>`);
      return;
    }

    if (req.method === 'GET' && url.pathname === '/fixture/cross-tab/b') {
      res.statusCode = 200;
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.end(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Cross-tab B</title>
  </head>
  <body style="font-family:system-ui">
    <h1>Cross-tab Tab B</h1>
    <a id="xt-reco-link" href="/fixture/cross-tab/a">
      <img id="xt-reco-thumb" src="/fixture/xt-thumb.bmp" alt="recommended" width="320" height="320" />
    </a>
  </body>
</html>`);
      return;
    }

    if (req.method === 'GET' && url.pathname === '/fixture/hero.bmp') {
      const bmp = createBmp(512, 512);
      res.statusCode = 200;
      res.setHeader('Content-Type', 'image/bmp');
      res.end(bmp);
      return;
    }

    if (req.method === 'GET' && url.pathname === '/fixture/wide.bmp') {
      const bmp = createBmp(800, 450);
      res.statusCode = 200;
      res.setHeader('Content-Type', 'image/bmp');
      res.end(bmp);
      return;
    }

    if (req.method === 'GET' && url.pathname === '/fixture/s1-small.bmp') {
      const bmp = createBmp(320, 320);
      res.statusCode = 200;
      res.setHeader('Content-Type', 'image/bmp');
      res.end(bmp);
      return;
    }
    if (req.method === 'GET' && url.pathname === '/fixture/s1-large.bmp') {
      const bmp = createBmp(900, 900);
      res.statusCode = 200;
      res.setHeader('Content-Type', 'image/bmp');
      res.end(bmp);
      return;
    }
    if (req.method === 'GET' && url.pathname === '/fixture/s2-large.bmp') {
      const bmp = createBmp(900, 900);
      res.statusCode = 200;
      res.setHeader('Content-Type', 'image/bmp');
      res.end(bmp);
      return;
    }
    if (req.method === 'GET' && url.pathname === '/fixture/s2-small.bmp') {
      const bmp = createBmp(280, 280);
      res.statusCode = 200;
      res.setHeader('Content-Type', 'image/bmp');
      res.end(bmp);
      return;
    }
    if (req.method === 'GET' && url.pathname === '/fixture/s3-large-initial.bmp') {
      const bmp = createBmp(920, 920);
      res.statusCode = 200;
      res.setHeader('Content-Type', 'image/bmp');
      res.end(bmp);
      return;
    }
    if (req.method === 'GET' && url.pathname === '/fixture/s3-large-modal.bmp') {
      const bmp = createBmp(960, 960);
      res.statusCode = 200;
      res.setHeader('Content-Type', 'image/bmp');
      res.end(bmp);
      return;
    }
    if (req.method === 'GET' && url.pathname === '/fixture/xt-main.bmp') {
      const bmp = createBmp(860, 860);
      res.statusCode = 200;
      res.setHeader('Content-Type', 'image/bmp');
      res.end(bmp);
      return;
    }
    if (req.method === 'GET' && url.pathname === '/fixture/xt-thumb.bmp') {
      const bmp = createBmp(320, 320);
      res.statusCode = 200;
      res.setHeader('Content-Type', 'image/bmp');
      res.end(bmp);
      return;
    }

    if (req.method === 'POST' && url.pathname === '/api/extension/files/check') {
      const token = req.headers['x-atlas-extension-token'];
      if (token !== 'test-token') {
        sendJson(401, { message: 'Invalid token' });
        return;
      }

      const body = await readJson(req);
      const urls = Array.isArray(body?.urls) ? body.urls : [];
      const results = urls.map((u: unknown) => {
        const key = typeof u === 'string' ? u.trim() : '';
        const record = key ? findBestRecordForLookup(key) : null;
        return {
          url: key,
          exists: Boolean(record),
          downloaded: Boolean(record?.downloaded),
          blacklisted: false,
          download_progress: record?.downloadProgress ?? 0,
          downloaded_at: record?.downloadedAt ?? null,
          file_id: record?.id ?? null,
          reaction: record?.reactionType ? { type: record.reactionType } : null,
        };
      });

      sendJson(200, { results });
      return;
    }

    if (req.method === 'POST' && url.pathname === '/api/extension/files') {
      const token = req.headers['x-atlas-extension-token'];
      if (token !== 'test-token') {
        sendJson(401, { message: 'Invalid token' });
        return;
      }

      const payload = await readJson(req);
      const mediaUrl = typeof payload?.url === 'string' ? payload.url : '';
      if (!mediaUrl) {
        sendJson(422, { message: 'url is required' });
        return;
      }

      const reactionType = typeof payload?.reaction_type === 'string' ? payload.reaction_type : '';
      if (!['love', 'like', 'dislike', 'funny'].includes(reactionType)) {
        sendJson(422, { message: 'reaction_type is required' });
        return;
      }

      const referrerUrl = typeof payload?.referrer_url === 'string' ? payload.referrer_url : null;
      const previewUrl = typeof payload?.preview_url === 'string' ? payload.preview_url : null;
      const { record, created } = findOrCreateRecord({
        url: mediaUrl,
        referrerUrl,
        previewUrl,
      });
      record.reactionType = reactionType;
      record.previewUrl = previewUrl || record.previewUrl;
      record.referrerUrl = referrerUrl || record.referrerUrl;

      // Simulate queueing then download completing shortly after.
      if (!record.downloaded && reactionType !== 'dislike') {
        record.downloadProgress = 24;
        setTimeout(() => {
          const current = records.find((candidate) => candidate.id === record.id);
          if (!current) {
            return;
          }

          current.downloaded = true;
          current.downloadProgress = 100;
          current.downloadedAt = new Date().toISOString();
        }, 250);
      }

      sendJson(created ? 201 : 200, {
        message: 'Reaction updated.',
        created,
        queued: reactionType !== 'dislike',
        file: {
          id: record.id,
          downloaded: record.downloaded,
          blacklisted_at: null,
          download_progress: record.downloadProgress,
          downloaded_at: record.downloadedAt,
          referrer_url: record.referrerUrl,
          preview_url: record.previewUrl,
        },
        reaction: { type: reactionType },
      });
      return;
    }

    if (req.method === 'POST' && url.pathname === '/api/extension/files/react') {
      const token = req.headers['x-atlas-extension-token'];
      if (token !== 'test-token') {
        sendJson(401, { message: 'Invalid token' });
        return;
      }

      const payload = await readJson(req);
      const mediaUrl = typeof payload?.url === 'string' ? payload.url : '';
      const reactionType = typeof payload?.type === 'string' ? payload.type : '';
      if (!mediaUrl || !['love', 'like', 'dislike', 'funny'].includes(reactionType)) {
        sendJson(422, { message: 'url and type are required' });
        return;
      }

      const referrerUrl = typeof payload?.referrer_url === 'string' ? payload.referrer_url : null;
      const previewUrl = typeof payload?.preview_url === 'string' ? payload.preview_url : null;
      const { record } = findOrCreateRecord({
        url: mediaUrl,
        referrerUrl,
        previewUrl,
      });

      record.reactionType = reactionType;
      record.referrerUrl = referrerUrl || record.referrerUrl;
      record.previewUrl = previewUrl || record.previewUrl;
      record.downloadProgress = reactionType === 'dislike' ? 0 : Math.max(record.downloadProgress, 15);
      if (reactionType === 'dislike') {
        record.downloaded = false;
        record.downloadedAt = null;
      }

      sendJson(200, {
        message: 'Reaction updated.',
        file: {
          id: record.id,
          downloaded: record.downloaded,
          blacklisted_at: null,
          download_progress: record.downloadProgress,
          downloaded_at: record.downloadedAt,
          referrer_url: record.referrerUrl,
          preview_url: record.previewUrl,
        },
        reaction: { type: reactionType },
      });
      return;
    }

    sendJson(404, { message: 'Not found' });
  });

  const port = await new Promise<number>((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const addr = server.address();
      if (addr && typeof addr === 'object') resolve(addr.port);
    });
  });

  return {
    baseUrl: `http://127.0.0.1:${port}`,
    seed: (
      url: string,
      options?: {
        downloaded?: boolean;
        reactionType?: string | null;
        referrerUrl?: string | null;
        previewUrl?: string | null;
        downloadProgress?: number;
        downloadedAt?: string | null;
      }
    ) => {
      const { record } = findOrCreateRecord({
        url,
        referrerUrl: options?.referrerUrl ?? null,
        previewUrl: options?.previewUrl ?? null,
      });

      record.downloaded = Boolean(options?.downloaded ?? record.downloaded);
      record.reactionType = options?.reactionType ?? record.reactionType ?? null;
      record.referrerUrl = options?.referrerUrl ?? record.referrerUrl;
      record.previewUrl = options?.previewUrl ?? record.previewUrl;
      record.downloadProgress = Math.max(0, Math.min(100, Number(options?.downloadProgress ?? (record.downloaded ? 100 : record.downloadProgress))));
      record.downloadedAt = options?.downloadedAt ?? record.downloadedAt;
      if (record.downloaded && !record.downloadedAt) {
        record.downloadedAt = new Date().toISOString();
      }
    },
    listRecords: () => records.map((record) => ({ ...record })),
    count: () => records.length,
    findByReferrer: (referrerUrl: string) => records.find((record) => (record.referrerUrl || '') === referrerUrl),
    findByUrl: (recordUrl: string) => records.find((record) => record.url === recordUrl),
    clear: () => {
      records.length = 0;
    },
    close: async () =>
      new Promise<void>((resolve, reject) => {
        server.close((err) => (err ? reject(err) : resolve()));
      }),
  };
}

function readJson(req: http.IncomingMessage): Promise<any> {
  return new Promise((resolve) => {
    let raw = '';
    req.setEncoding('utf8');
    req.on('data', (chunk) => (raw += chunk));
    req.on('end', () => {
      try {
        resolve(raw ? JSON.parse(raw) : null);
      } catch {
        resolve(null);
      }
    });
  });
}

async function launchWithExtension(): Promise<{
  context: BrowserContext;
  extensionId: string;
  userDataDir: string;
}> {
  const extensionPath = path.resolve('extension/atlas-downloader');
  const userDataDir = await fs.mkdtemp(path.join(os.tmpdir(), 'atlas-ext-'));

  const context = await chromium.launchPersistentContext(userDataDir, {
    headless: false,
    args: [
      `--disable-extensions-except=${extensionPath}`,
      `--load-extension=${extensionPath}`,
    ],
  });

  let sw = context.serviceWorkers()[0];
  if (!sw) {
    sw = await context.waitForEvent('serviceworker');
  }

  const extensionId = new URL(sw.url()).host;
  return { context, extensionId, userDataDir };
}

async function configureExtension(optionsPage: Page, extensionId: string, atlasBaseUrl: string) {
  await optionsPage.goto(`chrome-extension://${extensionId}/dist/options.html`);

  await optionsPage.locator('#baseUrl').fill(atlasBaseUrl);
  await optionsPage.locator('#token').fill('test-token');
  await optionsPage.getByRole('button', { name: /save settings/i }).click();

  await expect(optionsPage.getByText('Settings saved.')).toBeVisible();
}

async function forceOpenShadowMode(context: BrowserContext): Promise<void> {
  await context.addInitScript(() => {
    (window as Window & { __ATLAS_TEST_SHADOW_MODE?: string }).__ATLAS_TEST_SHADOW_MODE = 'open';

    const apply = () => {
      document.documentElement?.setAttribute('data-atlas-shadow-mode', 'open');
    };

    apply();
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', apply, { once: true });
    }
  });
}

async function openAtlasSheet(context: BrowserContext, page: Page): Promise<void> {
  const serviceWorker = context.serviceWorkers()[0] ?? (await context.waitForEvent('serviceworker'));
  const targetUrl = page.url();
  const isOpen = async (): Promise<boolean> => {
    return page.evaluate(() => {
      const host = document.getElementById('atlas-downloader-root');
      const shadow = host?.shadowRoot;
      const root = shadow?.querySelector('.atlas-shadow-root');
      return root instanceof HTMLElement && root.classList.contains('atlas-open');
    });
  };

  for (let attempt = 0; attempt < 40; attempt += 1) {
    if (await isOpen()) {
      return;
    }

    await serviceWorker.evaluate(async ({ pageUrl }) => {
      const extensionChrome = (globalThis as {
        chrome?: {
          tabs?: {
            query: (queryInfo: unknown) => Promise<Array<{ id?: number; url?: string }>>;
            sendMessage: (tabId: number, message: unknown) => Promise<unknown> | void;
          };
        };
      }).chrome;
      if (!extensionChrome?.tabs?.query || !extensionChrome?.tabs?.sendMessage) {
        return;
      }

      const tabs = await extensionChrome.tabs.query({});
      const exact = tabs.find((tab: { id?: number; url?: string }) => typeof tab.id === 'number' && tab.url === pageUrl);
      const fallback = tabs.find(
        (tab: { id?: number; url?: string }) =>
          typeof tab.id === 'number'
          && typeof tab.url === 'string'
          && tab.url.split('#')[0] === pageUrl.split('#')[0]
      );
      const target = exact ?? fallback;
      if (!target?.id) {
        return;
      }

      try {
        await extensionChrome.tabs.sendMessage(target.id, { type: 'atlas-open-sheet' });
      } catch {
        // Ignore transient "receiving end does not exist" while content script is still booting.
      }
    }, { pageUrl: targetUrl });

    await page.waitForTimeout(150);
  }

  await expect.poll(() => isOpen()).toBe(true);
}

async function expectBorderState(
  page: Page,
  selector: string,
  state: 'reacted' | 'exists' | 'blacklisted' | 'downloaded',
  reactionType?: 'love' | 'like' | 'dislike' | 'funny'
) {
  await expect
    .poll(async () => {
      return page.evaluate(
        ({ selector: sel }) => {
          const node = document.querySelector(sel) as HTMLElement | null;
          if (!node) {
            return null;
          }

          return {
            marked: node.getAttribute('data-atlas-marked'),
            state: node.getAttribute('data-atlas-state'),
            reaction: node.getAttribute('data-atlas-reaction'),
          };
        },
        { selector }
      );
    })
    .toEqual({
      marked: '1',
      state,
      reaction: reactionType ?? null,
    });
}

async function shadowCount(page: Page, selector: string): Promise<number> {
  return page.evaluate((sel) => {
    const host = document.getElementById('atlas-downloader-root');
    const shadow = host?.shadowRoot;
    return shadow?.querySelectorAll(sel).length ?? 0;
  }, selector);
}

async function clickShadowButtonByLabel(page: Page, label: string): Promise<void> {
  await page.evaluate((text) => {
    const host = document.getElementById('atlas-downloader-root');
    const shadow = host?.shadowRoot;
    if (!shadow) {
      throw new Error('Extension shadow root is not available.');
    }

    const normalized = text.trim().toLowerCase();
    const button = Array.from(shadow.querySelectorAll('button')).find((node) => {
      return node.textContent?.trim().toLowerCase() === normalized;
    });

    if (!(button instanceof HTMLElement)) {
      throw new Error(`Button not found: ${text}`);
    }

    button.click();
  }, label);
}

async function clickFirstShadowItem(page: Page): Promise<void> {
  await page.evaluate(() => {
    const host = document.getElementById('atlas-downloader-root');
    const shadow = host?.shadowRoot;
    const row = shadow?.querySelector('.atlas-downloader-item');
    if (!(row instanceof HTMLElement)) {
      throw new Error('No atlas-downloader-item row found.');
    }

    row.click();
  });
}

async function firstShadowItemSelected(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const host = document.getElementById('atlas-downloader-root');
    const shadow = host?.shadowRoot;
    const row = shadow?.querySelector('.atlas-downloader-item');
    return row instanceof HTMLElement && row.classList.contains('selected');
  });
}

async function hasDownloadedShadowStatus(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const host = document.getElementById('atlas-downloader-root');
    const shadow = host?.shadowRoot;
    if (!shadow) {
      return false;
    }

    return Array.from(shadow.querySelectorAll('.atlas-downloader-status')).some((node) => {
      return (node.textContent || '').trim() === 'Downloaded';
    });
  });
}

async function isShadowNodeVisible(page: Page, selector: string): Promise<boolean> {
  return page.evaluate((sel) => {
    const host = document.getElementById('atlas-downloader-root');
    const shadow = host?.shadowRoot;
    const node = shadow?.querySelector(sel);
    return node instanceof HTMLElement && !node.hidden;
  }, selector);
}

async function clickShadowPostIndicator(page: Page): Promise<void> {
  await page.evaluate(() => {
    const host = document.getElementById('atlas-downloader-root');
    const shadow = host?.shadowRoot;
    const button = shadow?.querySelector('.atlas-downloader-post-indicator');
    if (!(button instanceof HTMLElement)) {
      throw new Error('Post indicator button not found.');
    }

    button.dispatchEvent(
      new PointerEvent('pointerdown', {
        bubbles: true,
        cancelable: true,
        button: 0,
        altKey: true,
      })
    );
  });
}

function makeWixFixtureUrl(collectionId: string, assetId: string, size = 'w_360,h_360'): string {
  return `https://images-wixmp-ed30a86b8c4ca887773594c2.wixmp.com/f/${collectionId}/${assetId}.jpg/v1/fit/${size},q_70,strp/${assetId}.jpg?token=test-token`;
}

test('atlas-downloader: local fixture (select + queue + downloaded state)', async () => {
  const stub = await startStubAtlasServer();
  const { context, extensionId, userDataDir } = await launchWithExtension();

  try {
    await forceOpenShadowMode(context);

    const optionsPage = await context.newPage();
    await configureExtension(optionsPage, extensionId, stub.baseUrl);
    await optionsPage.close();

    const page = await context.newPage();
    // The content script intentionally does not run on Atlas' own host.
    // Use localhost for the fixture page, while Atlas API is configured on 127.0.0.1.
    const fixtureUrl = stub.baseUrl.replace('127.0.0.1', 'localhost');
    await page.goto(`${fixtureUrl}/fixture`);

    await openAtlasSheet(context, page);

    await expect.poll(() => shadowCount(page, '.atlas-downloader-item')).toBe(2);

    // Make selection behavior obvious and test row click toggles.
    await clickShadowButtonByLabel(page, 'Select none');
    await expect.poll(() => shadowCount(page, '.atlas-downloader-item.selected')).toBe(0);

    await clickFirstShadowItem(page);
    await expect.poll(() => firstShadowItemSelected(page)).toBe(true);

    await clickShadowButtonByLabel(page, 'Queue selected');

    // Wait for backend completion, then refresh sheet status to reflect downloaded state.
    await expect
      .poll(() => stub.listRecords().some((record) => record.downloaded))
      .toBe(true);
    await clickShadowButtonByLabel(page, 'Check Atlas');
    await expect.poll(() => hasDownloadedShadowStatus(page)).toBe(true);
  } finally {
    await context.close();
    await stub.close();
    await fs.rm(userDataDir, { recursive: true, force: true });
  }
});

test('atlas-downloader: wallhaven picker smoke test', async () => {
  test.skip(process.env.E2E_REMOTE !== '1', 'Set E2E_REMOTE=1 to run remote-site smoke tests.');

  // Remote sites can be flaky or blocked; keep this as a smoke test.
  const stub = await startStubAtlasServer();
  const { context, extensionId, userDataDir } = await launchWithExtension();

  try {
    const optionsPage = await context.newPage();
    await configureExtension(optionsPage, extensionId, stub.baseUrl);
    await optionsPage.close();

    const page = await context.newPage();
    await page.goto('https://wallhaven.cc/w/e83mxl', { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#atlas-downloader-toggle')).toBeVisible();
    await page.locator('#atlas-downloader-toggle').click();
    await expect(page.locator('.atlas-downloader-modal')).toBeVisible();

    // At minimum, ensure we don't list our own extension URL.
    const urls = await page.locator('.atlas-downloader-url').allTextContents();
    expect(urls.every((u) => !u.startsWith('chrome-extension://'))).toBeTruthy();
  } finally {
    await context.close();
    await stub.close();
    await fs.rm(userDataDir, { recursive: true, force: true });
  }
});

test('atlas-downloader scenario1: small and modal large share reaction border', async () => {
  const stub = await startStubAtlasServer();
  const { context, extensionId, userDataDir } = await launchWithExtension();

  try {
    const fixtureUrl = stub.baseUrl.replace('127.0.0.1', 'localhost');
    stub.seed(`${fixtureUrl}/fixture/s1-large.bmp`, {
      downloaded: true,
      reactionType: 'love',
    });

    const optionsPage = await context.newPage();
    await configureExtension(optionsPage, extensionId, stub.baseUrl);
    await optionsPage.close();

    const page = await context.newPage();
    await page.goto(`${fixtureUrl}/fixture/scenario1`);

    await expectBorderState(page, '#s1-thumb', 'reacted', 'love');
    await page.locator('#s1-link').click();
    await expect(page.locator('#modal')).toHaveClass(/open/);
    await expectBorderState(page, '#s1-modal-image', 'reacted', 'love');
  } finally {
    await context.close();
    await stub.close();
    await fs.rm(userDataDir, { recursive: true, force: true });
  }
});

test('atlas-downloader scenario2: initial large can be reacted, modal small gets neutral border', async () => {
  const stub = await startStubAtlasServer();
  const { context, extensionId, userDataDir } = await launchWithExtension();

  try {
    const fixtureUrl = stub.baseUrl.replace('127.0.0.1', 'localhost');
    stub.seed(`${fixtureUrl}/fixture/s2-large.bmp`, {
      downloaded: true,
      reactionType: 'love',
    });
    stub.seed(`${fixtureUrl}/fixture/s2-small.bmp`, {
      downloaded: false,
      reactionType: null,
    });

    const optionsPage = await context.newPage();
    await configureExtension(optionsPage, extensionId, stub.baseUrl);
    await optionsPage.close();

    const page = await context.newPage();
    await page.goto(`${fixtureUrl}/fixture/scenario2`);

    await expectBorderState(page, '#s2-main', 'reacted', 'love');
    await page.locator('#s2-open-modal').click();
    await expect(page.locator('#modal')).toHaveClass(/open/);
    await expectBorderState(page, '#s2-modal-image', 'exists');
  } finally {
    await context.close();
    await stub.close();
    await fs.rm(userDataDir, { recursive: true, force: true });
  }
});

test('atlas-downloader scenario3: dynamically injected modal image is marked after open', async () => {
  const stub = await startStubAtlasServer();
  const { context, extensionId, userDataDir } = await launchWithExtension();

  try {
    const fixtureUrl = stub.baseUrl.replace('127.0.0.1', 'localhost');
    stub.seed(`${fixtureUrl}/fixture/s3-large-modal.bmp`, {
      downloaded: true,
      reactionType: 'like',
    });

    const optionsPage = await context.newPage();
    await configureExtension(optionsPage, extensionId, stub.baseUrl);
    await optionsPage.close();

    const page = await context.newPage();
    await page.goto(`${fixtureUrl}/fixture/scenario3`);
    await page.locator('#s3-link').click();
    await expect(page.locator('#modal')).toHaveClass(/open/);
    await expect(page.locator('#s3-modal-image')).toBeVisible();
    await expectBorderState(page, '#s3-modal-image', 'reacted', 'like');
  } finally {
    await context.close();
    await stub.close();
    await fs.rm(userDataDir, { recursive: true, force: true });
  }
});

test('atlas-downloader cross-tab: reacting in tab A updates recommended media outline in tab B', async () => {
  const stub = await startStubAtlasServer();
  const { context, extensionId, userDataDir } = await launchWithExtension();

  try {
    const fixtureUrl = stub.baseUrl.replace('127.0.0.1', 'localhost');
    const optionsPage = await context.newPage();
    await configureExtension(optionsPage, extensionId, stub.baseUrl);
    await optionsPage.close();

    const tabA = await context.newPage();
    const tabB = await context.newPage();

    await tabA.goto(`${fixtureUrl}/fixture/cross-tab/a`);
    await tabB.goto(`${fixtureUrl}/fixture/cross-tab/b`);

    await expect
      .poll(async () => {
        return tabB.evaluate(() => {
          const node = document.querySelector('#xt-reco-thumb');
          if (!(node instanceof HTMLElement)) {
            return null;
          }

          return node.getAttribute('data-atlas-state');
        });
      })
      .toBeNull();

    await tabA.locator('#xt-main').dispatchEvent('mousedown', {
      altKey: true,
      button: 0,
      clientX: 24,
      clientY: 24,
    });

    await expect
      .poll(() => stub.findByReferrer(`${fixtureUrl}/fixture/cross-tab/a`)?.reactionType ?? null)
      .toBe('like');

    await expectBorderState(tabB, '#xt-reco-thumb', 'reacted', 'like');
  } finally {
    await context.close();
    await stub.close();
    await fs.rm(userDataDir, { recursive: true, force: true });
  }
});

test('atlas-downloader deviantart fixture: post CTA queues all post images with #image-N referrers and persists markers', async () => {
  const stub = await startStubAtlasServer();
  const { context, extensionId, userDataDir } = await launchWithExtension();

  try {
    await forceOpenShadowMode(context);

    const postUrl = 'https://www.deviantart.com/forgebond/art/The-Women-I-Met-in-a-VRMMORPG-08-Wizard-1301970367';
    const basePostUrl = postUrl.split('#')[0];
    const collectionId = 'dmocked123456';
    const wixUrls = [
      makeWixFixtureUrl(collectionId, 'daaa1111-aaa1'),
      makeWixFixtureUrl(collectionId, 'daaa2222-aaa2'),
      makeWixFixtureUrl(collectionId, 'daaa3333-aaa3'),
      makeWixFixtureUrl(collectionId, 'daaa4444-aaa4'),
    ];

    const optionsPage = await context.newPage();
    await configureExtension(optionsPage, extensionId, stub.baseUrl);
    await optionsPage.close();

    const page = await context.newPage();
    await page.route(`${postUrl}*`, async (route) => {
      const html = `<!doctype html>
<html data-atlas-shadow-mode="open">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>The Women I Met in a VRMMORPG 08 Wizard</title>
    <meta property="og:image" content="${wixUrls[0]}" />
    <link rel="canonical" href="${postUrl}" />
    <style>
      body { margin: 0; font-family: system-ui; background: #0f172a; color: #f1f5f9; }
      main { max-width: 980px; margin: 0 auto; padding: 16px; }
      #da-main { width: 860px; height: 860px; object-fit: cover; border-radius: 10px; cursor: zoom-in; display: block; }
      #thumb-rail { display: flex; gap: 10px; margin-top: 14px; }
      #thumb-rail .NpoINo img { width: 140px; height: 140px; object-fit: cover; border-radius: 8px; display: block; }
      #modal { position: fixed; inset: 0; background: rgba(2,6,23,.7); display: none; align-items: center; justify-content: center; }
      #modal.open { display: flex; }
      #modal-content { background: #111827; border: 1px solid rgba(148,163,184,.3); padding: 10px; border-radius: 10px; }
      #modal-image { width: 900px; height: 900px; object-fit: cover; display: block; border-radius: 8px; }
      #close-modal { margin-bottom: 8px; }
    </style>
  </head>
  <body>
    <main>
      <h1>The Women I Met in a VRMMORPG 08 Wizard</h1>
      <img id="da-main" src="${wixUrls[0]}" alt="main image" />
      <div id="thumb-rail" class="IUfj2J qeNdP5 bOFPMd">
        ${wixUrls
          .map(
            (url, index) => `<div class="NpoINo">
              <a id="thumb-link-${index + 1}" href="${basePostUrl}#image-${index + 1}">
                <img id="thumb-${index + 1}" src="${url}" alt="thumb-${index + 1}" />
              </a>
            </div>`
          )
          .join('\n')}
      </div>
    </main>
    <div id="modal">
      <div id="modal-content">
        <button id="close-modal" type="button">Close</button>
        <img id="modal-image" src="${wixUrls[0]}" alt="modal image" />
      </div>
    </div>
    <script>
      const urls = ${JSON.stringify(wixUrls)};
      const main = document.getElementById('da-main');
      const modal = document.getElementById('modal');
      const modalImage = document.getElementById('modal-image');
      const close = document.getElementById('close-modal');
      const syncFromHash = () => {
        const match = location.hash.match(/^#image-(\\d+)$/);
        const index = match ? Number(match[1]) - 1 : 0;
        const next = urls[index] || urls[0];
        main.src = next;
        modalImage.src = next;
      };
      document.querySelectorAll('#thumb-rail a').forEach((anchor, index) => {
        anchor.addEventListener('click', (event) => {
          event.preventDefault();
          history.replaceState({}, '', '${basePostUrl}#image-' + (index + 1));
          syncFromHash();
        });
      });
      main.addEventListener('click', () => {
        modal.classList.add('open');
      });
      close.addEventListener('click', () => modal.classList.remove('open'));
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          modal.classList.remove('open');
        }
      });
      syncFromHash();
    </script>
  </body>
</html>`;
      await route.fulfill({
        status: 200,
        contentType: 'text/html; charset=utf-8',
        body: html,
      });
    });

    await page.route('https://images-wixmp-ed30a86b8c4ca887773594c2.wixmp.com/**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'image/bmp',
        body: createBmp(900, 900),
      });
    });

    await page.goto(postUrl);
    await page.hover('#da-main');
    await expect
      .poll(async () => isShadowNodeVisible(page, '.atlas-downloader-post-indicator'))
      .toBe(true);

    await clickShadowPostIndicator(page);

    await expect.poll(() => stub.count()).toBe(4);
    await expect
      .poll(() => {
        const referrers = stub
          .listRecords()
          .map((record) => record.referrerUrl || '')
          .sort();
        return referrers;
      })
      .toEqual([
        `${basePostUrl}#image-1`,
        `${basePostUrl}#image-2`,
        `${basePostUrl}#image-3`,
        `${basePostUrl}#image-4`,
      ]);

    await expectBorderState(page, '#thumb-1', 'reacted', 'like');
    await expectBorderState(page, '#thumb-2', 'reacted', 'like');
    await expectBorderState(page, '#thumb-3', 'reacted', 'like');
    await expectBorderState(page, '#thumb-4', 'reacted', 'like');

    await page.reload();
    await expectBorderState(page, '#thumb-3', 'reacted', 'like');
  } finally {
    await context.close();
    await stub.close();
    await fs.rm(userDataDir, { recursive: true, force: true });
  }
});

test('atlas-downloader: deviantart live smoke (optional)', async () => {
  test.skip(process.env.E2E_REMOTE !== '1', 'Set E2E_REMOTE=1 to run live DeviantArt smoke tests.');

  const stub = await startStubAtlasServer();
  const { context, extensionId, userDataDir } = await launchWithExtension();

  try {
    const optionsPage = await context.newPage();
    await configureExtension(optionsPage, extensionId, stub.baseUrl);
    await optionsPage.close();

    const page = await context.newPage();
    await page.goto(
      'https://www.deviantart.com/forgebond/art/The-Women-I-Met-in-a-VRMMORPG-08-Wizard-1301970367',
      { waitUntil: 'domcontentloaded' }
    );
    await expect(page.locator('#atlas-downloader-toggle')).toBeVisible();
  } finally {
    await context.close();
    await stub.close();
    await fs.rm(userDataDir, { recursive: true, force: true });
  }
});
