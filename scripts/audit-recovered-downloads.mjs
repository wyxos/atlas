#!/usr/bin/env node

import { execFileSync } from 'node:child_process'
import {
  existsSync,
  mkdirSync,
  statSync,
  writeFileSync,
} from 'node:fs'
import path from 'node:path'
import { pathToFileURL } from 'node:url'
import { printResult, printUsage } from './recovered-downloads-cli-output.mjs'
import { classifyLostFile } from './recovered-downloads-classifier.mjs'

export { classifyLostFile } from './recovered-downloads-classifier.mjs'

const DEFAULT_RECOVERED_ROOT = 'D:\\Recovered\\Files(F)\\0000 - Downloads'
const DEFAULT_MARKER = '0000 - Downloads'
const DEFAULT_REMOTE_PATH = '/home/wyxos/webapps/atlas'
const DEFAULT_SSH_HOST = 'wyxos'

export function relativePathFromMarker(legacyPath, marker = DEFAULT_MARKER) {
  const normalized = String(legacyPath ?? '').replaceAll('\\', '/')
  const markerIndex = normalized.toLowerCase().indexOf(marker.toLowerCase())

  if (markerIndex === -1) {
    return null
  }

  const relative = normalized.slice(markerIndex + marker.length).replace(/^\/+/, '')

  return relative === '' ? null : relative
}

export function auditLegacyRows(rows, options = {}) {
  const recoveredRoot = options.recoveredRoot ?? DEFAULT_RECOVERED_ROOT
  const marker = options.marker ?? DEFAULT_MARKER
  const found = []
  const notFound = []
  const sizeMismatches = []
  const lostPriority = []
  const lostReview = []
  const lostJunk = []
  const summary = {
    total: 0,
    found: 0,
    notFound: 0,
    lostPriority: 0,
    lostReview: 0,
    lostJunk: 0,
    sameSize: 0,
    sizeMismatch: 0,
    unknownSize: 0,
    skipped: 0,
  }

  for (const row of rows) {
    const relativePath = relativePathFromMarker(row.path, marker)

    if (!relativePath) {
      summary.skipped += 1
      continue
    }

    summary.total += 1

    const recoveredPath = recoveredPathForRelative(recoveredRoot, relativePath)
    const baseResult = {
      fileId: Number(row.id),
      relativePath,
      legacyPath: String(row.path ?? ''),
      expectedSize: numberOrNull(row.size),
      storedHash: row.hash ?? '',
    }

    if (!recoveredPath || !existsSync(recoveredPath) || !statSync(recoveredPath).isFile()) {
      const classification = classifyLostFile(relativePath)
      const lostResult = {
        ...baseResult,
        extension: classification.extension,
        lostBucket: classification.bucket,
        lostReason: classification.reason,
      }

      summary.notFound += 1
      notFound.push(lostResult)
      addLostBucketResult(classification.bucket, lostResult, {
        lostJunk,
        lostPriority,
        lostReview,
        summary,
      })
      continue
    }

    const recoveredSize = statSync(recoveredPath).size
    const sizeStatus = sizeStatusFor(baseResult.expectedSize, recoveredSize)
    const foundResult = {
      ...baseResult,
      recoveredPath,
      recoveredSize,
      sizeStatus,
    }

    summary.found += 1
    found.push(foundResult)

    if (sizeStatus === 'same') {
      summary.sameSize += 1
    } else if (sizeStatus === 'mismatch') {
      summary.sizeMismatch += 1
      sizeMismatches.push(foundResult)
    } else {
      summary.unknownSize += 1
    }
  }

  return { found, notFound, sizeMismatches, lostPriority, lostReview, lostJunk, summary }
}

export function writeReports(reportDirectory, result) {
  mkdirSync(reportDirectory, { recursive: true })

  const found = path.join(reportDirectory, 'recovered-found.csv')
  const notFound = path.join(reportDirectory, 'forever-lost.csv')
  const sizeMismatches = path.join(reportDirectory, 'size-mismatch.csv')
  const lostPriority = path.join(reportDirectory, 'lost-priority.csv')
  const lostReview = path.join(reportDirectory, 'lost-review.csv')
  const lostJunk = path.join(reportDirectory, 'lost-junk.csv')
  const lostPriorityRows = result.lostPriority ?? result.notFound.filter((item) => item.lostBucket === 'priority')
  const lostReviewRows = result.lostReview ?? result.notFound.filter((item) => item.lostBucket === 'review')
  const lostJunkRows = result.lostJunk ?? result.notFound.filter((item) => item.lostBucket === 'junk')

  writeCsv(found, [
    'file_id',
    'relative_path',
    'legacy_path',
    'recovered_path',
    'expected_size',
    'recovered_size',
    'size_status',
    'stored_hash',
  ], result.found.map((item) => [
    item.fileId,
    item.relativePath,
    item.legacyPath,
    item.recoveredPath,
    item.expectedSize ?? '',
    item.recoveredSize,
    item.sizeStatus,
    item.storedHash ?? '',
  ]))

  writeCsv(notFound, [
    'file_id',
    'relative_path',
    'legacy_path',
    'extension',
    'lost_bucket',
    'lost_reason',
    'expected_size',
    'stored_hash',
  ], result.notFound.map((item) => [
    item.fileId,
    item.relativePath,
    item.legacyPath,
    item.extension ?? '',
    item.lostBucket ?? '',
    item.lostReason ?? '',
    item.expectedSize ?? '',
    item.storedHash ?? '',
  ]))

  writeLostBucketCsv(lostPriority, lostPriorityRows)
  writeLostBucketCsv(lostReview, lostReviewRows)
  writeLostBucketCsv(lostJunk, lostJunkRows)

  writeCsv(sizeMismatches, [
    'file_id',
    'relative_path',
    'legacy_path',
    'recovered_path',
    'expected_size',
    'recovered_size',
    'stored_hash',
  ], result.sizeMismatches.map((item) => [
    item.fileId,
    item.relativePath,
    item.legacyPath,
    item.recoveredPath,
    item.expectedSize ?? '',
    item.recoveredSize,
    item.storedHash ?? '',
  ]))

  return { found, notFound, sizeMismatches, lostPriority, lostReview, lostJunk }
}

export function csvLine(values) {
  return values.map((value) => {
    const stringValue = value === null || value === undefined ? '' : String(value)

    if (!/[",\r\n]/.test(stringValue)) {
      return stringValue
    }

    return `"${stringValue.replaceAll('"', '""')}"`
  }).join(',')
}

function writeCsv(file, headers, rows) {
  writeFileSync(file, [
    csvLine(headers),
    ...rows.map((row) => csvLine(row)),
  ].join('\n') + '\n', 'utf8')
}

function writeLostBucketCsv(file, rows) {
  writeCsv(file, [
    'file_id',
    'relative_path',
    'legacy_path',
    'extension',
    'lost_bucket',
    'lost_reason',
    'expected_size',
    'stored_hash',
  ], rows.map((item) => [
    item.fileId,
    item.relativePath,
    item.legacyPath,
    item.extension ?? '',
    item.lostBucket ?? '',
    item.lostReason ?? '',
    item.expectedSize ?? '',
    item.storedHash ?? '',
  ]))
}

function addLostBucketResult(bucket, item, state) {
  if (bucket === 'priority') {
    state.summary.lostPriority += 1
    state.lostPriority.push(item)

    return
  }

  if (bucket === 'junk') {
    state.summary.lostJunk += 1
    state.lostJunk.push(item)

    return
  }

  state.summary.lostReview += 1
  state.lostReview.push(item)
}

function recoveredPathForRelative(recoveredRoot, relativePath) {
  const root = path.resolve(recoveredRoot)
  const candidate = path.resolve(root, ...relativePath.split('/'))

  if (candidate !== root && !candidate.startsWith(`${root}${path.sep}`)) {
    return null
  }

  return candidate
}

function sizeStatusFor(expectedSize, recoveredSize) {
  if (expectedSize === null) {
    return 'unknown'
  }

  return expectedSize === recoveredSize ? 'same' : 'mismatch'
}

function numberOrNull(value) {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const number = Number(value)

  return Number.isFinite(number) ? number : null
}

function parseArgs(argv) {
  const options = {
    chunk: 500,
    color: true,
    dryRun: true,
    limit: 0,
    marker: DEFAULT_MARKER,
    recoveredRoot: DEFAULT_RECOVERED_ROOT,
    remotePath: DEFAULT_REMOTE_PATH,
    reportDir: defaultReportDirectory(),
    sshHost: DEFAULT_SSH_HOST,
  }

  for (const arg of argv) {
    if (arg === '--help' || arg === '-h') {
      options.help = true
    } else if (arg === '--dry-run') {
      options.dryRun = true
    } else if (arg === '--apply') {
      options.apply = true
    } else if (arg === '--no-color') {
      options.color = false
    } else if (arg.startsWith('--recovered-root=')) {
      options.recoveredRoot = arg.slice('--recovered-root='.length)
    } else if (arg.startsWith('--marker=')) {
      options.marker = arg.slice('--marker='.length)
    } else if (arg.startsWith('--ssh-host=')) {
      options.sshHost = arg.slice('--ssh-host='.length)
    } else if (arg.startsWith('--remote-path=')) {
      options.remotePath = arg.slice('--remote-path='.length)
    } else if (arg.startsWith('--report-dir=')) {
      options.reportDir = arg.slice('--report-dir='.length)
    } else if (arg.startsWith('--chunk=')) {
      options.chunk = Number(arg.slice('--chunk='.length))
    } else if (arg.startsWith('--limit=')) {
      options.limit = Number(arg.slice('--limit='.length))
    } else {
      throw new Error(`Unknown option: ${arg}`)
    }
  }

  if (options.apply) {
    throw new Error('This script is audit-only. Use --dry-run; no apply mode exists yet.')
  }

  options.chunk = positiveInteger(options.chunk, 'chunk')
  options.limit = nonNegativeInteger(options.limit, 'limit')

  return options
}

function positiveInteger(value, name) {
  if (!Number.isInteger(value) || value < 1) {
    throw new Error(`--${name} must be a positive integer.`)
  }

  return value
}

function nonNegativeInteger(value, name) {
  if (!Number.isInteger(value) || value < 0) {
    throw new Error(`--${name} must be zero or a positive integer.`)
  }

  return value
}

function defaultReportDirectory() {
  const stamp = new Date().toISOString().replaceAll(':', '').replace(/\.\d{3}Z$/, 'Z')

  return path.resolve('storage', 'app', 'reports', 'recovered-downloads', stamp)
}

function fetchLegacyRows(options) {
  const php = legacyRowsPhp(options.marker, options.chunk, options.limit)
  const output = execFileSync('ssh', [
    options.sshHost,
    `cd ${remoteShellQuote(options.remotePath)} && php`,
  ], {
    encoding: 'utf8',
    input: php,
    maxBuffer: 1024 * 1024 * 32,
    stdio: ['pipe', 'pipe', 'inherit'],
  })

  return output
    .split(/\r?\n/)
    .filter((line) => line.trim() !== '')
    .map((line) => JSON.parse(line))
}

function legacyRowsPhp(marker, chunk, limit) {
  return `<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Illuminate\\Support\\Facades\\DB;

$marker = ${phpString(marker)};
$like = '%'.$marker.'%';
$remaining = ${limit};

$query = DB::table('files')
    ->where('source', 'local')
    ->whereNull('imported_at')
    ->where('path', 'like', $like)
    ->select(['id', 'path', 'size', 'hash', 'source', 'downloaded', 'downloaded_at'])
    ->orderBy('id');

$query->chunkById(${chunk}, function ($rows) use (&$remaining): bool {
    foreach ($rows as $row) {
        if (${limit} > 0 && $remaining <= 0) {
            return false;
        }

        if (${limit} > 0) {
            $remaining--;
        }

        echo json_encode([
            'id' => (int) $row->id,
            'path' => (string) $row->path,
            'size' => $row->size === null ? null : (int) $row->size,
            'hash' => $row->hash,
            'source' => $row->source,
            'downloaded' => (bool) $row->downloaded,
            'downloaded_at' => $row->downloaded_at,
        ], JSON_UNESCAPED_SLASHES), PHP_EOL;
    }

    return ${limit} === 0 || $remaining > 0;
});
`
}

function phpString(value) {
  return `'${String(value).replaceAll('\\', '\\\\').replaceAll('\'', '\\\'')}'`
}

function remoteShellQuote(value) {
  return `'${String(value).replaceAll('\'', '\'\\\'\'')}'`
}

async function main() {
  const options = parseArgs(process.argv.slice(2))

  if (options.help) {
    printUsage({
      marker: DEFAULT_MARKER,
      recoveredRoot: DEFAULT_RECOVERED_ROOT,
      remotePath: DEFAULT_REMOTE_PATH,
      sshHost: DEFAULT_SSH_HOST,
    })

    return
  }

  if (!existsSync(options.recoveredRoot)) {
    throw new Error(`Recovered root does not exist: ${options.recoveredRoot}`)
  }

  const rows = fetchLegacyRows(options)
  const result = auditLegacyRows(rows, options)
  const reportPaths = writeReports(options.reportDir, result)
  printResult(result, reportPaths, options)
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  main().catch((error) => {
    console.error(error instanceof Error ? error.message : error)
    process.exit(1)
  })
}
