// Playwright + CDP trace for /browse after local impersonation
// Usage:
//   ATLAS_URL=https://atlas.test EMAIL=wyxos@proton.me CPU_THROTTLE=4 HEADLESS=1 node scripts/perf/trace-browse.mjs
// Outputs:
//   - playwright-trace.zip (view with: npx playwright show-trace playwright-trace.zip)
//   - cdp-trace.json (open in chrome://tracing or https://ui.perfetto.dev)
//   - perf-metrics.json (basic navigation timing)

import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.ATLAS_URL || 'https://atlas.test';
const EMAIL = process.env.EMAIL || 'wyxos@proton.me';
const CPU_THROTTLE = Number(process.env.CPU_THROTTLE || 4);
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

async function main() {
  const browser = await chromium.launch({ headless: HEADLESS });
  const context = await browser.newContext();
  const page = await context.newPage();

  // Enable Playwright tracing (nice UI for step-by-step replay)
  await context.tracing.start({ title: 'browse-load', screenshots: true, snapshots: true, sources: true });

  const client = await context.newCDPSession(page);
  // CPU slowdown to make long tasks easier to spot
  try { await client.send('Emulation.setCPUThrottlingRate', { rate: CPU_THROTTLE }); } catch {}
  // Start CDP tracing and set up event to get the stream handle when complete
  await client.send('Tracing.start', { categories: categories.join(','), transferMode: 'ReturnAsStream', options: 'record-as-much-as-possible', streamCompression: 'none' });
  const traceStreamHandle = new Promise((resolve) => {
    try {
      client.on('Tracing.tracingComplete', ({ stream }) => resolve(stream));
    } catch {
      resolve(undefined);
    }
  });

  // Impersonate locally and land on /browse
  const imp = `${BASE}/dev/impersonate?email=${encodeURIComponent(EMAIL)}`;
  console.log(`[perf] Navigating to impersonate: ${imp}`);
  await page.goto(imp, { waitUntil: 'domcontentloaded' });
  await page.waitForURL(/\/browse(\?.*)?$/, { waitUntil: 'domcontentloaded' });

  // Wait a short while to let the first meaningful paint settle
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(300);

  // Collect basic perf metrics
  const metrics = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    return nav ? {
      name: nav.name,
      startTime: nav.startTime,
      duration: nav.duration,
      domContentLoaded: nav.domContentLoadedEventEnd,
      loadEventEnd: nav.loadEventEnd,
      transferSize: nav.transferSize,
      encodedBodySize: nav.encodedBodySize,
      decodedBodySize: nav.decodedBodySize,
    } : null;
  });
if (metrics) fs.writeFileSync(path.join(OUT_DIR, 'perf-metrics.json'), JSON.stringify(metrics, null, 2));

  // Stop CDP tracing and save
  await client.send('Tracing.end');
  const handle = await traceStreamHandle;
  if (handle) {
    const chunks = [];
    while (true) {
      const res = await client.send('IO.read', { handle });
      const chunk = res.base64Encoded ? Buffer.from(res.data, 'base64') : Buffer.from(res.data, 'utf8');
      chunks.push(chunk);
      if (res.eof) break;
    }
    await client.send('IO.close', { handle });
fs.writeFileSync(path.join(OUT_DIR, 'cdp-trace.json'), Buffer.concat(chunks));
  } else {
    // Could not retrieve stream handle; skip CDP saving but keep Playwright trace
fs.writeFileSync(path.join(OUT_DIR, 'cdp-trace.json'), Buffer.from(''));
  }

  // Stop Playwright trace
await context.tracing.stop({ path: path.join(OUT_DIR, 'playwright-trace.zip') });
  await browser.close();

console.log(`[perf] Wrote to ${OUT_DIR}: playwright-trace.zip, cdp-trace.json, perf-metrics.json`);
console.log(`[perf] View Playwright trace: npx playwright show-trace "${path.join(OUT_DIR, 'playwright-trace.zip')}"`);
console.log(`[perf] View CDP trace: chrome://tracing or https://ui.perfetto.dev (open ${path.join(OUT_DIR, 'cdp-trace.json')})`);
}

main().catch((err) => {
  console.error(err);
  process.exitCode = 1;
});