import { test, expect, chromium, type BrowserContext, type Page } from '@playwright/test';
import http from 'node:http';
import path from 'node:path';
import os from 'node:os';
import fs from 'node:fs/promises';

type AtlasRecord = {
  id: number;
  url: string;
  downloaded: boolean;
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

      let record = records.get(mediaUrl);
      const created = !record;
      if (!record) {
        record = { id: id++, url: mediaUrl, downloaded: false };
        records.set(mediaUrl, record);
      }

      // Simulate queueing then download completing shortly after.
      if (!record.downloaded) {
        setTimeout(() => {
          const current = records.get(mediaUrl);
          if (current) current.downloaded = true;
        }, 250);
      }

      sendJson(created ? 201 : 200, {
        message: created ? 'Download queued.' : 'File stored.',
        created,
        queued: true,
        file: { id: record.id, downloaded: record.downloaded },
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
  await optionsPage.goto(`chrome-extension://${extensionId}/options.html`);

  await optionsPage.locator('#atlasBaseUrl').fill(atlasBaseUrl);
  await optionsPage.locator('#atlasToken').fill('test-token');
  await optionsPage.locator('#atlasExcludedDomains').fill('');
  await optionsPage.getByRole('button', { name: 'Save settings' }).click();

  await expect(optionsPage.locator('#status')).toHaveText('Settings saved.');
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
