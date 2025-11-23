import { readFileSync, writeFileSync } from 'fs';
import { join } from 'path';
import { chromium } from 'playwright';

// Simple ICO file header structure
function createIcoFile(pngBuffer: Buffer): Buffer {
    // ICO file format: header + directory + image data
    const icoHeader = Buffer.alloc(6);
    icoHeader.writeUInt16LE(0, 0); // Reserved (must be 0)
    icoHeader.writeUInt16LE(1, 2); // Type (1 = ICO)
    icoHeader.writeUInt16LE(1, 4); // Number of images
    
    // Directory entry (16 bytes)
    const directory = Buffer.alloc(16);
    directory.writeUInt8(32, 0); // Width (0 = 256, but we use actual size)
    directory.writeUInt8(32, 1); // Height
    directory.writeUInt8(0, 2); // Color palette (0 = no palette)
    directory.writeUInt8(0, 3); // Reserved
    directory.writeUInt16LE(1, 4); // Color planes
    directory.writeUInt16LE(32, 6); // Bits per pixel
    directory.writeUInt32LE(pngBuffer.length, 8); // Image data size
    directory.writeUInt32LE(22, 12); // Offset to image data (6 + 16)
    
    return Buffer.concat([icoHeader, directory, pngBuffer]);
}

const sizes = [
    // Favicons
    { name: 'favicon-16x16.png', size: 16 },
    { name: 'favicon-32x32.png', size: 32 },
    { name: 'favicon-48x48.png', size: 48 },
    // Android Chrome
    { name: 'android-chrome-36x36.png', size: 36 },
    { name: 'android-chrome-48x48.png', size: 48 },
    { name: 'android-chrome-72x72.png', size: 72 },
    { name: 'android-chrome-96x96.png', size: 96 },
    { name: 'android-chrome-144x144.png', size: 144 },
    { name: 'android-chrome-192x192.png', size: 192 },
    { name: 'android-chrome-256x256.png', size: 256 },
    { name: 'android-chrome-384x384.png', size: 384 },
    { name: 'android-chrome-512x512.png', size: 512 },
    // Apple Touch Icons
    { name: 'apple-touch-icon.png', size: 180 },
    { name: 'apple-touch-icon-57x57.png', size: 57 },
    { name: 'apple-touch-icon-60x60.png', size: 60 },
    { name: 'apple-touch-icon-72x72.png', size: 72 },
    { name: 'apple-touch-icon-76x76.png', size: 76 },
    { name: 'apple-touch-icon-114x114.png', size: 114 },
    { name: 'apple-touch-icon-120x120.png', size: 120 },
    { name: 'apple-touch-icon-144x144.png', size: 144 },
    { name: 'apple-touch-icon-152x152.png', size: 152 },
    { name: 'apple-touch-icon-167x167.png', size: 167 },
    { name: 'apple-touch-icon-180x180.png', size: 180 },
    { name: 'apple-touch-icon-1024x1024.png', size: 1024 },
    { name: 'apple-touch-icon-precomposed.png', size: 180 },
    // MS Tiles
    { name: 'mstile-70x70.png', size: 70 },
    { name: 'mstile-144x144.png', size: 144 },
    { name: 'mstile-150x150.png', size: 150 },
    { name: 'mstile-310x150.png', size: 310, height: 150 },
    { name: 'mstile-310x310.png', size: 310 },
];

async function generateFavicons(): Promise<void> {
    const svgPath = join(process.cwd(), 'resources', 'svg', 'atlas-icon.svg');
    const publicPath = join(process.cwd(), 'public');
    const svgContent = readFileSync(svgPath, 'utf-8');

    console.log('Starting favicon generation...');
    
    const browser = await chromium.launch();
    const page = await browser.newPage();

    // Create a data URL from the SVG
    const svgDataUrl = `data:image/svg+xml;base64,${Buffer.from(svgContent).toString('base64')}`;

    for (const { name, size, height } of sizes) {
        const width = size;
        const imageHeight = height || size;
        
        await page.setViewportSize({ width, height: imageHeight });
        await page.setContent(`
            <!DOCTYPE html>
            <html>
                <head>
                    <style>
                        body {
                            margin: 0;
                            padding: 0;
                            width: ${width}px;
                            height: ${imageHeight}px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        }
                        img {
                            width: ${width}px;
                            height: ${imageHeight}px;
                            object-fit: contain;
                        }
                    </style>
                </head>
                <body>
                    <img src="${svgDataUrl}" />
                </body>
            </html>
        `);

        const screenshot = await page.screenshot({
            type: 'png',
            clip: { x: 0, y: 0, width, height: imageHeight },
        });

        const outputPath = join(publicPath, name);
        writeFileSync(outputPath, screenshot);
        console.log(`Generated: ${name}`);
    }

    // Generate favicon.ico from 32x32 PNG
    console.log('Generating favicon.ico...');
    const favicon32Path = join(publicPath, 'favicon-32x32.png');
    const favicon32Buffer = readFileSync(favicon32Path);
    const icoBuffer = createIcoFile(favicon32Buffer);
    writeFileSync(join(publicPath, 'favicon.ico'), icoBuffer);
    console.log('Generated: favicon.ico');

    await browser.close();
    console.log('Favicon generation complete!');
}

generateFavicons().catch((error) => {
    console.error('Error generating favicons:', error);
    process.exit(1);
});

