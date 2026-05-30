import { mkdtemp, readFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import process from 'node:process'
import { spawnSync } from 'node:child_process'

const stableReleaseTypes = ['major', 'minor', 'patch']
const releaseTypeWeights = { patch: 1, minor: 2, major: 3 }

function parseArgs(argv) {
  const options = {
    currentVersion: '0.0.0',
    packageName: 'extension',
    pathPrefix: 'extension',
    rootDir: process.cwd(),
    minimumReleaseType: '',
  }

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index]
    const readValue = () => {
      index += 1
      return argv[index] ?? ''
    }

    if (arg === '--current-version') options.currentVersion = readValue()
    else if (arg.startsWith('--current-version=')) options.currentVersion = arg.slice('--current-version='.length)
    else if (arg === '--package-name') options.packageName = readValue()
    else if (arg.startsWith('--package-name=')) options.packageName = arg.slice('--package-name='.length)
    else if (arg === '--path-prefix') options.pathPrefix = readValue()
    else if (arg.startsWith('--path-prefix=')) options.pathPrefix = arg.slice('--path-prefix='.length)
    else if (arg === '--root-dir') options.rootDir = readValue()
    else if (arg.startsWith('--root-dir=')) options.rootDir = arg.slice('--root-dir='.length)
    else if (arg === '--minimum') options.minimumReleaseType = readValue()
    else if (arg.startsWith('--minimum=')) options.minimumReleaseType = arg.slice('--minimum='.length)
    else if (arg === '--help' || arg === '-h') options.help = true
    else throw new Error(`Unknown option: ${arg}`)
  }

  return options
}

function sanitizeReleaseType(message) {
  if (typeof message !== 'string') return null

  const normalized = message
    .trim()
    .toLowerCase()
    .replace(/^release type:\s*/i, '')
    .split(/\s+/)[0]
    ?.replace(/[^a-z]/g, '')

  return stableReleaseTypes.includes(normalized) ? normalized : null
}

function applyReleaseTypeFloor(releaseType, floorReleaseType) {
  const releaseWeight = releaseTypeWeights[releaseType] ?? -1
  const floorWeight = releaseTypeWeights[floorReleaseType] ?? -1
  return floorWeight > releaseWeight ? floorReleaseType : releaseType
}

function runCapture(command, args, cwd) {
  const result = spawnSync(command, args, {
    cwd,
    encoding: 'utf8',
    shell: false,
    windowsHide: true,
  })

  if (result.error) throw result.error
  if (result.status !== 0) {
    throw new Error(`${command} ${args.join(' ')} exited with code ${result.status}`)
  }

  return String(result.stdout ?? '').trim()
}

function commandExists(command) {
  const probe = process.platform === 'win32' ? 'where' : 'command'
  const args = process.platform === 'win32' ? [command] : ['-v', command]
  const result = spawnSync(probe, args, {
    encoding: 'utf8',
    shell: process.platform !== 'win32',
    windowsHide: true,
  })
  return result.status === 0
}

function readLatestReference(rootDir) {
  try {
    return runCapture('git', ['describe', '--tags', '--abbrev=0'], rootDir)
  } catch {
    return null
  }
}

function readCommitLog(rootDir, latestReference, pathPrefix) {
  const range = latestReference ? `${latestReference}..HEAD` : '-30'

  try {
    return runCapture('git', ['log', '--format=%h %s', range, '--', pathPrefix], rootDir)
  } catch {
    return ''
  }
}

function readDiffStat(rootDir, latestReference, pathPrefix) {
  const args = latestReference
    ? ['diff', '--stat', `${latestReference}..HEAD`, '--', pathPrefix]
    : ['diff', '--stat', 'HEAD~30..HEAD', '--', pathPrefix]

  try {
    return runCapture('git', args, rootDir)
  } catch {
    return ''
  }
}

function readWorkingTreeStatus(rootDir, pathPrefix) {
  try {
    return runCapture('git', ['status', '--short', '--', pathPrefix], rootDir)
  } catch {
    return ''
  }
}

function readWorkingTreeDiffStat(rootDir, pathPrefix) {
  try {
    return runCapture('git', ['diff', '--stat', 'HEAD', '--', pathPrefix], rootDir)
  } catch {
    return ''
  }
}

function inferReleaseTypeHeuristically({ commitLog = '', diffStat = '', workingTreeStatus = '', workingTreeDiffStat = '' }) {
  const combined = `${commitLog}\n${diffStat}\n${workingTreeStatus}\n${workingTreeDiffStat}`

  if (/breaking change|breaking changes|^[a-z]+(?:\(.+\))?!:/im.test(combined)) {
    return 'major'
  }

  if (/\bfeat(?:\(.+\))?:/im.test(commitLog) || /\bfeature\b/i.test(combined)) {
    return 'minor'
  }

  return 'patch'
}

function collectDiagnosticLines(output = '') {
  return String(output)
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => /\b(error|warn|warning|failed|failure|rejected|declined)\b/i.test(line))
}

async function askCodex(rootDir, context) {
  const tempDir = await mkdtemp(path.join(tmpdir(), 'extension-release-type-'))
  const outputPath = path.join(tempDir, 'codex-last-message.txt')

  try {
    const result = spawnSync('codex', [
      'exec',
      '--ephemeral',
      '--ignore-user-config',
      '--model',
      'gpt-5.4-mini',
      '--sandbox',
      'read-only',
      '--skip-git-repo-check',
      '--output-last-message',
      outputPath,
      [
        'Choose exactly one semver release type for this browser extension package.',
        'Reply with exactly one of: major, minor, patch.',
        'Base the choice on actual extension code and workflow changes since the latest release reference.',
        `Package: ${context.packageName}`,
        `Current version: ${context.currentVersion}`,
        `Latest release reference: ${context.latestReference || 'none found'}`,
        'Commits for the extension since the latest release reference:',
        context.commitLog || '- no commits found',
        'Diff summary for the extension since the latest release reference:',
        context.diffStat || '- no diff summary available',
        'Current working-tree extension status:',
        context.workingTreeStatus || '- no working-tree extension changes',
        'Current working-tree extension diff summary:',
        context.workingTreeDiffStat || '- no working-tree extension diff summary',
      ].join('\n\n'),
    ], {
      cwd: rootDir,
      encoding: 'utf8',
      shell: false,
      windowsHide: true,
    })

    const diagnostics = [
      ...collectDiagnosticLines(result.stdout),
      ...String(result.stderr ?? '').split(/\r?\n/).filter(Boolean),
    ]

    if (result.error) throw result.error
    if (result.status !== 0) {
      throw new Error(`Codex release advisor exited with code ${result.status}; captured ${diagnostics.length} diagnostic lines.`)
    }

    if (diagnostics.length > 0) {
      console.error(`Codex release advisor emitted ${diagnostics.length} diagnostic lines; using returned suggestion.`)
    }

    return sanitizeReleaseType(await readFile(outputPath, 'utf8'))
  } finally {
    await rm(tempDir, { recursive: true, force: true }).catch(() => {})
  }
}

async function suggestReleaseType(options) {
  const rootDir = path.resolve(options.rootDir)
  const latestReference = readLatestReference(rootDir)
  const commitLog = readCommitLog(rootDir, latestReference, options.pathPrefix)
  const diffStat = readDiffStat(rootDir, latestReference, options.pathPrefix)
  const workingTreeStatus = readWorkingTreeStatus(rootDir, options.pathPrefix)
  const workingTreeDiffStat = readWorkingTreeDiffStat(rootDir, options.pathPrefix)
  const heuristic = applyReleaseTypeFloor(
    inferReleaseTypeHeuristically({ commitLog, diffStat, workingTreeStatus, workingTreeDiffStat }),
    sanitizeReleaseType(options.minimumReleaseType),
  )

  if (!commandExists('codex')) {
    return heuristic
  }

  try {
    const codexSuggestion = await askCodex(rootDir, {
      packageName: options.packageName,
      currentVersion: options.currentVersion,
      latestReference,
      commitLog,
      diffStat,
      workingTreeStatus,
      workingTreeDiffStat,
    })

    return codexSuggestion ? applyReleaseTypeFloor(codexSuggestion, heuristic) : heuristic
  } catch (error) {
    console.error(`${error instanceof Error ? error.message : String(error)} Using heuristic release type.`)
    return heuristic
  }
}

try {
  const options = parseArgs(process.argv.slice(2))
  if (options.help) {
    console.log('Usage: node scripts/suggest-extension-version-bump.mjs --current-version <version> [--package-name <name>] [--path-prefix extension]')
    process.exit(0)
  }

  console.log(await suggestReleaseType(options))
} catch (error) {
  console.error(error instanceof Error ? error.message : String(error))
  process.exit(1)
}
