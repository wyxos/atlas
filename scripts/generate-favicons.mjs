import { promises as fs } from 'node:fs';
import path from 'node:path';
import favicons from 'favicons';

const projectRoot = path.resolve(process.cwd());
const inputPath = path.join(projectRoot, 'resources', 'images', 'logo.png');
const outDir = path.join(projectRoot, 'public');

async function ensureDir(dir) {
  await fs.mkdir(dir, { recursive: true }).catch(() => {});
}

async function run() {
  try {
    // Ensure input exists
    await fs.access(inputPath);

    await ensureDir(outDir);

    const configuration = {
      path: '/',
      appName: 'Atlas',
      appShortName: 'Atlas',
      appDescription: 'Atlas media server',
      theme_color: '#ffffff',
      background: '#ffffff',
      icons: {
        android: true,
        appleIcon: true,
        appleStartup: false,
        coast: false,
        favicons: true,
        windows: true,
        yandex: false,
      },
    };

    const response = await favicons(inputPath, configuration);

    // Write images
    for (const img of response.images) {
      const dest = path.join(outDir, img.name);
      await fs.writeFile(dest, img.contents);
    }

    // Write files (site.webmanifest, browserconfig.xml, etc.)
    for (const file of response.files) {
      const dest = path.join(outDir, file.name);
      await fs.writeFile(dest, file.contents);
    }

    // Output HTML snippet for wiring into Blade
    const html = response.html.join('\n');
    // Wrap in markers so downstream automation can extract
    console.log('===FAVICONS_HTML_BEGIN===');
    console.log(html);
    console.log('===FAVICONS_HTML_END===');
  } catch (err) {
    console.error('[favicons] generation failed:', err?.message || err);
    process.exit(1);
  }
}

run();
