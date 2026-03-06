import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const projectRoot = path.resolve(import.meta.dirname, '..');
const automationConfigDirectory = path.join(projectRoot, '.playwright-mcp');
const sourceExtensionDirectory = path.join(projectRoot, 'extension', 'dist');
const defaultDownloadsDirectory = path.join(os.homedir(), 'Downloads');
const extensionAutomationDirectory = path.resolve(
    process.env.ATLAS_EXTENSION_AUTOMATION_DIR
        ?? path.join(defaultDownloadsDirectory, 'atlas-extension-automation'),
);
const braveProfileDirectory = path.resolve(
    process.env.ATLAS_BRAVE_AUTOMATION_PROFILE_DIR
        ?? path.join(automationConfigDirectory, 'profile-brave-automation'),
);
const braveExecutablePath = path.resolve(
    process.env.ATLAS_BRAVE_EXECUTABLE
        ?? 'C:/Program Files/BraveSoftware/Brave-Browser/Application/brave.exe',
);
const remoteDebuggingPort = Number.parseInt(process.env.ATLAS_BRAVE_REMOTE_DEBUGGING_PORT ?? '9222', 10);

function toConfigPath(absolutePath) {
    return absolutePath.replace(/\\/g, '/');
}

function ensureDirectoryExists(directory) {
    fs.mkdirSync(directory, { recursive: true });
}

function syncExtensionBuild() {
    if (!fs.existsSync(sourceExtensionDirectory)) {
        throw new Error(`Extension dist directory not found: ${sourceExtensionDirectory}`);
    }

    fs.rmSync(extensionAutomationDirectory, { recursive: true, force: true });
    ensureDirectoryExists(extensionAutomationDirectory);
    fs.cpSync(sourceExtensionDirectory, extensionAutomationDirectory, { recursive: true });
}

function writeLocalPlaywrightConfig() {
    ensureDirectoryExists(automationConfigDirectory);

    const config = {
        browser: {
            browserName: 'chromium',
            userDataDir: toConfigPath(braveProfileDirectory),
            launchOptions: {
                executablePath: toConfigPath(braveExecutablePath),
                headless: false,
                args: [
                    `--disable-extensions-except=${toConfigPath(extensionAutomationDirectory)}`,
                    `--load-extension=${toConfigPath(extensionAutomationDirectory)}`,
                ],
            },
        },
    };

    fs.writeFileSync(
        path.join(automationConfigDirectory, 'config.json'),
        `${JSON.stringify(config, null, 2)}\n`,
        'utf8',
    );
}

function runPowerShell(script) {
    const result = spawnSync(
        'pwsh',
        ['-NoProfile', '-Command', script],
        {
            cwd: projectRoot,
            encoding: 'utf8',
        },
    );

    if (result.status !== 0) {
        throw new Error(result.stderr.trim() || result.stdout.trim() || 'PowerShell command failed.');
    }
}

function escapePowerShellSingleQuoted(value) {
    return value.replace(/'/g, "''");
}

function restartBraveAutomationProfile() {
    const profilePath = escapePowerShellSingleQuoted(braveProfileDirectory);
    const extensionPath = escapePowerShellSingleQuoted(extensionAutomationDirectory);
    const executablePath = escapePowerShellSingleQuoted(braveExecutablePath);

    runPowerShell(`
$profilePath = '${profilePath}'
$extensionPath = '${extensionPath}'
$executablePath = '${executablePath}'

Get-CimInstance Win32_Process |
    Where-Object { $_.Name -match 'brave' -and $_.CommandLine -like "*$profilePath*" } |
    ForEach-Object {
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }

Start-Sleep -Seconds 1

Start-Process -FilePath $executablePath -ArgumentList @(
    '--remote-debugging-port=${remoteDebuggingPort}',
    "--user-data-dir=$profilePath",
    "--disable-extensions-except=$extensionPath",
    "--load-extension=$extensionPath"
)
`);
}

try {
    syncExtensionBuild();
    writeLocalPlaywrightConfig();
    restartBraveAutomationProfile();

    console.log(`Synced unpacked extension to ${extensionAutomationDirectory}`);
    console.log(`Updated local Playwright MCP config at ${path.join(automationConfigDirectory, 'config.json')}`);
    console.log(`Restarted isolated Brave automation profile at ${braveProfileDirectory}`);
} catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    process.exitCode = 1;
}
