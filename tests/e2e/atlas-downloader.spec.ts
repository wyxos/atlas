import { test, expect, chromium, type BrowserContext, type Page } from '@playwright/test';
import http from 'node:http';
import path from 'node:path';
import os from 'node:os';
import fs from 'node:fs/promises';

type AtlasRecord = {
  id: number;
  url: string;
  downloaded: boolean;
  reactionType: string | null;
};

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
  const records = new Map<string, AtlasRecord>();
  let id = 1;

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
    <a id="s2-link" href="${`/fixture/s2-small.bmp`}">
      <img id="s2-main" src="${`/fixture/s2-large.bmp`}" alt="large-initial" />
    </a>
    <div id="modal">
      <div id="modal-content">
        <button id="close-modal">Close</button>
        <img id="s2-modal-image" src="${`/fixture/s2-small.bmp`}" alt="small-modal" />
      </div>
    </div>
    <script>
      const link = document.getElementById('s2-link');
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

    if (req.method === 'POST' && url.pathname === '/api/extension/files/check') {
      const token = req.headers['x-atlas-extension-token'];
      if (token !== 'test-token') {
        sendJson(401, { message: 'Invalid token' });
        return;
      }

      const body = await readJson(req);
      const urls = Array.isArray(body?.urls) ? body.urls : [];
      const results = urls.map((u: unknown) => {
        const key = typeof u === 'string' ? u : '';
        const record = key ? records.get(key) : undefined;
        return {
          url: key,
          exists: Boolean(record),
          downloaded: Boolean(record?.downloaded),
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

      let record = records.get(mediaUrl);
      const created = !record;
      if (!record) {
        record = { id: id++, url: mediaUrl, downloaded: false, reactionType: null };
        records.set(mediaUrl, record);
      }
      record.reactionType = reactionType;

      // Simulate queueing then download completing shortly after.
      if (!record.downloaded && reactionType !== 'dislike') {
        setTimeout(() => {
          const current = records.get(mediaUrl);
          if (current) current.downloaded = true;
        }, 250);
      }

      sendJson(created ? 201 : 200, {
        message: 'Reaction updated.',
        created,
        queued: reactionType !== 'dislike',
        file: { id: record.id, downloaded: record.downloaded },
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
    seed: (url: string, options?: { downloaded?: boolean; reactionType?: string | null }) => {
      const existing = records.get(url);
      if (existing) {
        existing.downloaded = Boolean(options?.downloaded ?? existing.downloaded);
        existing.reactionType = options?.reactionType ?? existing.reactionType ?? null;
        return;
      }

      records.set(url, {
        id: id++,
        url,
        downloaded: Boolean(options?.downloaded ?? false),
        reactionType: options?.reactionType ?? null,
      });
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

  await optionsPage.locator('#atlasBaseUrl').fill(atlasBaseUrl);
  await optionsPage.locator('#atlasToken').fill('test-token');
  await optionsPage.locator('#atlasExcludedDomains').fill('');
  await optionsPage.getByRole('button', { name: 'Save settings' }).click();

  await expect(optionsPage.locator('#status')).toHaveText('Settings saved.');
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

test('atlas-downloader: local fixture (select + queue + downloaded state)', async () => {
  const stub = await startStubAtlasServer();
  const { context, extensionId, userDataDir } = await launchWithExtension();

  try {
    const optionsPage = await context.newPage();
    await configureExtension(optionsPage, extensionId, stub.baseUrl);
    await optionsPage.close();

    const page = await context.newPage();
    // The content script intentionally does not run on Atlas' own host.
    // Use localhost for the fixture page, while Atlas API is configured on 127.0.0.1.
    const fixtureUrl = stub.baseUrl.replace('127.0.0.1', 'localhost');
    await page.goto(`${fixtureUrl}/fixture`);

    await page.locator('#atlas-downloader-toggle').click();
    await expect(page.locator('.atlas-downloader-modal')).toBeVisible();

    await expect(page.locator('.atlas-downloader-item')).toHaveCount(2);

    // Make selection behavior obvious and test row click toggles.
    await page.getByRole('button', { name: 'Select none' }).click();
    await expect(page.locator('.atlas-downloader-item.selected')).toHaveCount(0);

    const firstRow = page.locator('.atlas-downloader-item').first();
    await firstRow.click();
    await expect(firstRow).toHaveClass(/selected/);

    await page.getByRole('button', { name: 'Queue selected' }).click();

    // Polling should flip to Downloaded.
    await expect
      .poll(async () => {
        const statuses = await page.locator('.atlas-downloader-status').allTextContents();
        return statuses.some((t) => t.trim() === 'Downloaded');
      })
      .toBe(true);
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
    await page.locator('#s2-link').click();
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
