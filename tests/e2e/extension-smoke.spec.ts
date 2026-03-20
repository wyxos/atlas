import { expect, test, chromium, type BrowserContext } from '@playwright/test';
import { createServer, type Server } from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const currentFilePath = fileURLToPath(import.meta.url);
const currentDirectory = path.dirname(currentFilePath);
const repoRoot = path.resolve(currentDirectory, '..', '..');
const fixturesDirectory = path.join(currentDirectory, 'fixtures');
const fallbackExtensionDirectory = path.join(repoRoot, 'extension', 'dist');
const automationExtensionDirectory = process.env.ATLAS_EXTENSION_AUTOMATION_DIR?.trim()
    ? path.resolve(process.env.ATLAS_EXTENSION_AUTOMATION_DIR)
    : path.join(os.homedir(), 'Downloads', 'atlas-extension-automation');

const configuredExecutablePath = process.env.ATLAS_BRAVE_EXECUTABLE?.trim() ?? '';
const defaultBraveExecutablePath = process.platform === 'win32'
    ? 'C:/Program Files/BraveSoftware/Brave-Browser/Application/brave.exe'
    : null;

type SmokePrerequisites = {
    executablePath: string | null;
    extensionDirectory: string | null;
    skipReason: string | null;
};

function resolveExistingExtensionDirectory(): string | null {
    for (const candidate of [automationExtensionDirectory, fallbackExtensionDirectory]) {
        const popupPath = path.join(candidate, 'popup.html');
        const optionsPath = path.join(candidate, 'options.html');
        const contentPath = path.join(candidate, 'content.js');
        const manifestPath = path.join(candidate, 'manifest.json');
        if (
            fs.existsSync(popupPath)
            && fs.existsSync(optionsPath)
            && fs.existsSync(contentPath)
            && fs.existsSync(manifestPath)
        ) {
            return candidate;
        }
    }

    return null;
}

function resolveExecutablePath(): string | null {
    if (configuredExecutablePath !== '') {
        const absolutePath = path.resolve(configuredExecutablePath);
        return fs.existsSync(absolutePath) ? absolutePath : null;
    }

    if (defaultBraveExecutablePath && fs.existsSync(defaultBraveExecutablePath)) {
        return defaultBraveExecutablePath;
    }

    return null;
}

function resolveSmokePrerequisites(): SmokePrerequisites {
    const extensionDirectory = resolveExistingExtensionDirectory();
    if (extensionDirectory === null) {
        return {
            executablePath: resolveExecutablePath(),
            extensionDirectory: null,
            skipReason: 'No unpacked Atlas extension build was found. Run `npm run build:extension` or `npm run test:extension:automation` first.',
        };
    }

    return {
        executablePath: resolveExecutablePath(),
        extensionDirectory,
        skipReason: null,
    };
}

function resolveFixtureRequestPath(requestUrl: string | undefined): string {
    const pathname = requestUrl ? new URL(requestUrl, 'http://127.0.0.1').pathname : '/';
    return pathname === '/' ? '/extension-target.html' : pathname;
}

function resolveContentType(filePath: string): string {
    switch (path.extname(filePath).toLowerCase()) {
        case '.css':
            return 'text/css; charset=utf-8';
        case '.html':
            return 'text/html; charset=utf-8';
        case '.js':
            return 'text/javascript; charset=utf-8';
        case '.json':
            return 'application/json; charset=utf-8';
        case '.svg':
            return 'image/svg+xml';
        default:
            return 'application/octet-stream';
    }
}

function startFixtureServer(): Promise<{ baseUrl: string; server: Server }> {
    return new Promise((resolve, reject) => {
        const server = createServer((request, response) => {
            const fixtureRequestPath = resolveFixtureRequestPath(request.url);
            const filePath = path.resolve(fixturesDirectory, `.${fixtureRequestPath}`);

            if (!filePath.startsWith(fixturesDirectory)) {
                response.writeHead(403, { 'Content-Type': 'text/plain; charset=utf-8' });
                response.end('Forbidden');
                return;
            }

            if (!fs.existsSync(filePath) || fs.statSync(filePath).isDirectory()) {
                response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
                response.end('Not found');
                return;
            }

            response.writeHead(200, { 'Content-Type': resolveContentType(filePath) });
            fs.createReadStream(filePath).pipe(response);
        });

        server.once('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const address = server.address();
            if (!address || typeof address === 'string') {
                reject(new Error('Failed to resolve the fixture server address.'));
                return;
            }

            resolve({
                baseUrl: `http://127.0.0.1:${address.port}`,
                server,
            });
        });
    });
}

async function resolveExtensionId(context: BrowserContext): Promise<string> {
    const serviceWorker = context.serviceWorkers()[0]
        ?? await context.waitForEvent('serviceworker', { timeout: 30_000 });

    return new URL(serviceWorker.url()).host;
}

const prerequisites = resolveSmokePrerequisites();

test.describe.serial('Atlas extension smoke', () => {
    test.skip(prerequisites.skipReason !== null, prerequisites.skipReason ?? '');

    let context: BrowserContext;
    let extensionId = '';
    let fixtureBaseUrl = '';
    let fixtureServer: Server | null = null;
    let profileDirectory = '';

    test.beforeAll(async () => {
        const fixture = await startFixtureServer();
        fixtureBaseUrl = fixture.baseUrl;
        fixtureServer = fixture.server;
        profileDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'atlas-extension-smoke-'));

        context = await chromium.launchPersistentContext(profileDirectory, {
            ...(prerequisites.executablePath ? { executablePath: prerequisites.executablePath } : {}),
            headless: false,
            args: [
                `--disable-extensions-except=${prerequisites.extensionDirectory}`,
                `--load-extension=${prerequisites.extensionDirectory}`,
            ],
        });
        extensionId = await resolveExtensionId(context);
    });

    test.afterAll(async () => {
        await context?.close();
        await new Promise<void>((resolve, reject) => {
            if (!fixtureServer) {
                resolve();
                return;
            }

            fixtureServer.close((error) => {
                if (error) {
                    reject(error);
                    return;
                }

                resolve();
            });
        });

        if (profileDirectory !== '') {
            fs.rmSync(profileDirectory, { recursive: true, force: true });
        }
    });

    test('loads the popup and options extension pages', async () => {
        const popupPage = await context.newPage();
        await popupPage.goto(`chrome-extension://${extensionId}/popup.html`);

        await expect(popupPage.getByRole('heading', { name: 'Atlas Browser Extension' })).toBeVisible();
        await expect(popupPage.getByRole('button', { name: 'Open Options' })).toBeVisible();
        await expect(popupPage.getByText('Version')).toBeVisible();
        await popupPage.close();

        const optionsPage = await context.newPage();
        await optionsPage.goto(`chrome-extension://${extensionId}/options.html`);

        await expect(optionsPage.getByRole('heading', { name: 'Atlas Extension Options' })).toBeVisible();
        await expect(optionsPage.getByText('Runtime Diagnostics')).toBeVisible();
        await expect(optionsPage.getByRole('button', { name: 'Save Changes' })).toBeVisible();
        await optionsPage.close();
    });

    test('injects the content script on a controlled media page', async () => {
        const page = await context.newPage();
        await page.goto(`${fixtureBaseUrl}/extension-target.html`);

        const standaloneMedia = page.locator('#standalone-media');
        const linkedMedia = page.locator('#linked-media');

        await expect
            .poll(async () => standaloneMedia.getAttribute('data-atlas-media-red-applied'))
            .toBe('1');
        await expect(page.locator('[data-atlas-media-red-badge="1"]')).toHaveCount(1);
        await expect(linkedMedia).not.toHaveAttribute('data-atlas-media-red-applied', '1');
        await page.close();
    });
});
