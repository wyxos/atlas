// Interaction performance trace for /browse (ALT+left click, ALT+right click)
// Usage:
//   ATLAS_URL=https://atlas.test EMAIL=wyxos@proton.me HEADLESS=1 node scripts/perf/trace-browse-interact.mjs
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.ATLAS_URL || 'https://atlas.test';
const EMAIL = process.env.EMAIL || 'wyxos@proton.me';
const HEADLESS = String(process.env.HEADLESS || '1') !== '0';

// Organized output directory per run
const OUTPUT_ROOT = process.env.PERF_OUT_DIR || 'perf-artifacts';
const RUN_ID = new Date().toISOString().replace(/[:.]/g, '-');
const OUT_DIR = path.join(OUTPUT_ROOT, RUN_ID);
try { fs.mkdirSync(OUT_DIR, { recursive: true }); } catch {}

const categories = [
  'devtools.timeline',
  'disabled-by-default-devtools.timeline',
  'v8.execute',
  'blink.user_timing',
  'loading',
  'disabled-by-default-v8.cpu_profiler',
];

async function startTracing(client) {
  await client.send('Tracing.start', {
    categories: categories.join(','),
    transferMode: 'ReturnAsStream',
    options: 'record-as-much-as-possible',
    streamCompression: 'none',
  });
  return new Promise((resolve) => {
    client.on('Tracing.tracingComplete', ({ stream }) => resolve(stream));
  });
}

async function stopTracing(client, streamHandlePromise, outPath) {
  await client.send('Tracing.end');
  const handle = await streamHandlePromise;
  if (!handle) return;
  const chunks = [];
  while (true) {
    const res = await client.send('IO.read', { handle });
    const chunk = res.base64Encoded ? Buffer.from(res.data, 'base64') : Buffer.from(res.data, 'utf8');
    chunks.push(chunk);
    if (res.eof) break;
  }
  await client.send('IO.close', { handle });
  fs.writeFileSync(outPath, Buffer.concat(chunks));
}

async function main() {
  const SLOWMO = Number(process.env.SLOWMO || 0);
  const KEEP_OPEN = String(process.env.KEEP_OPEN || '0') === '1';
  const DEBUG_ONLY = String(process.env.DEBUG_ONLY || '0') === '1';
  console.log(`[perf] Launching Chromium (headless=${HEADLESS}, slowMo=${SLOWMO})`);
  const browser = await chromium.launch({ headless: HEADLESS, slowMo: SLOWMO });
  const context = await browser.newContext();
  context.setDefaultTimeout(15000);
  const page = await context.newPage();
  page.on('console', (m) => console.log('[page]', m.type(), m.text()));
  page.on('pageerror', (e) => console.log('[pageerror]', e.message));
  const client = await context.newCDPSession(page);

  // Navigate and impersonate
  const imp = `${BASE}/dev/impersonate?email=${encodeURIComponent(EMAIL)}`;
  console.log('[perf] Impersonating:', imp);
  await page.goto(imp, { waitUntil: 'domcontentloaded' });
  console.log('[perf] Waiting for /browse...');
  await page.waitForURL(/\/browse(\?.*)?$/, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle');
  console.log('[perf] /browse loaded (networkidle).');

  // Optional pre-scroll to load more pages
  const SCROLL_PAGES = Number(process.env.SCROLL_PAGES || 0);
  if (SCROLL_PAGES > 0) {
    console.log(`[perf] Pre-scrolling ${SCROLL_PAGES} pages to load more items...`);
    for (let i = 0; i < SCROLL_PAGES; i++) {
      await page.mouse.wheel(0, 2000);
      await page.waitForTimeout(700);
    }
  }

  // Ensure a visible thumbnail in the LIST view (not full-size). We scope to grid container (.p-1)
  console.log('[perf] Locating thumbnail in grid (div.p-1 img|video)...');
  let media = page.locator('div.p-1 img.opacity-100, div.p-1 video.opacity-100').first();
  try {
    await media.waitFor({ state: 'visible', timeout: 20000 });
  } catch {
    console.log('[perf] Loaded grid media not found, falling back to first grid media');
    media = page.locator('div.p-1 img, div.p-1 video').first();
    await media.waitFor({ timeout: 20000 });
  }
  try { await media.evaluate((el) => el.scrollIntoView({ block: 'center', inline: 'center' })); } catch {}
  const box = await media.boundingBox();
  if (!box) throw new Error('No bounding box for grid media');
  console.log('[perf] Grid media box:', box);

  // ALT + LEFT CLICK (like)
  console.log('[perf] Start trace: ALT+LEFT click');
  let streamP;
  if (!DEBUG_ONLY) streamP = startTracing(client);
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  // Dispatch both mousedown and click with altKey to match GridItem handlers
  await media.evaluate((el) => {
    el.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, altKey: true, button: 0 }));
    el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, altKey: true, button: 0 }));
  });
  console.log('[perf] ALT+LEFT dispatched via synthetic events');
  // Wait for tile removal (media detached or hidden)
  try { await media.waitFor({ state: 'detached', timeout: 2000 }); } catch {}
  await page.waitForTimeout(600); // small settle
  if (!DEBUG_ONLY) {
    const out = SCROLL_PAGES > 0 ? 'cdp-alt-left-after-scroll.json' : 'cdp-alt-left.json';
await stopTracing(client, streamP, path.join(OUT_DIR, out));
    console.log(`[perf] Saved ${out}`);
  }
try { await page.screenshot({ path: path.join(OUT_DIR, SCROLL_PAGES > 0 ? 'after-alt-left-after-scroll.png' : 'after-alt-left.png'), fullPage: false }); } catch {}

  // Re-query media in case previous removal mutated DOM
  console.log('[perf] Re-locating media after left action...');
  let media2 = page.locator('img.opacity-100, video.opacity-100').first();
  try { await media2.waitFor({ state: 'visible', timeout: 20000 }); } catch {}
  const box2 = await media2.boundingBox();
  const targetBox = box2 || box; // fallback to original

  // ALT + RIGHT CLICK (dislike)
  console.log('[perf] Start trace: ALT+RIGHT click');
  if (!DEBUG_ONLY) streamP = startTracing(client);
  await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height / 2);
  await page.mouse.click(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height / 2, { modifiers: ['Alt'], button: 'right' });
  console.log('[perf] ALT+RIGHT dispatched');
  await page.waitForTimeout(800);
  if (!DEBUG_ONLY) {
    const out = SCROLL_PAGES > 0 ? 'cdp-alt-right-after-scroll.json' : 'cdp-alt-right.json';
await stopTracing(client, streamP, path.join(OUT_DIR, out));
    console.log(`[perf] Saved ${out}`);
  }
try { await page.screenshot({ path: path.join(OUT_DIR, SCROLL_PAGES > 0 ? 'after-alt-right-after-scroll.png' : 'after-alt-right.png'), fullPage: false }); } catch {}

  if (KEEP_OPEN) {
    console.log('[perf] KEEP_OPEN=1 — keeping browser open for 30s');
    await page.waitForTimeout(30000);
  }
  await browser.close();
console.log(`[perf] Done. Files saved under: ${OUT_DIR}`);
}

main().catch((err) => { console.error(err); process.exitCode = 1; });