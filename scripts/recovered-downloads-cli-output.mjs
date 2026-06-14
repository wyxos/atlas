export function printUsage(defaults) {
  console.log(`Usage: node scripts/audit-recovered-downloads.mjs [options]

Options:
  --recovered-root=PATH  Local recovered folder. Default: ${defaults.recoveredRoot}
  --ssh-host=HOST       SSH alias for Atlas production. Default: ${defaults.sshHost}
  --remote-path=PATH    Atlas production path. Default: ${defaults.remotePath}
  --marker=TEXT         Legacy path marker. Default: ${defaults.marker}
  --report-dir=PATH     Directory for CSV reports.
  --limit=N             Limit legacy rows for a smoke run. Default: 0 (all)
  --chunk=N             Remote DB chunk size. Default: 500
  --no-color            Disable colored status labels.
  --dry-run             Explicit audit mode. The script never mutates data.

Reports:
  recovered-found.csv   Rows found under the recovered root.
  forever-lost.csv      Full not-found list with lost_bucket/lost_reason columns.
  lost-priority.csv     Not-found audio, video, and archive/package rows.
  lost-review.csv       Not-found image, subtitle, document, playlist, and unknown rows.
  lost-junk.csv         Not-found system, release metadata, shortcut, and pointer rows.
`)
}

export function printResult(result, reportPaths, options) {
  const paint = colors(options.color)

  console.log(`Mode: ${paint.cyan('dry-run')}`)
  console.log(`Recovered root: ${options.recoveredRoot}`)
  console.log(`Reports: ${options.reportDir}`)
  console.log(
    `Summary: total=${result.summary.total} found=${result.summary.found} not_found=${result.summary.notFound} ` +
    `same_size=${result.summary.sameSize} size_mismatch=${result.summary.sizeMismatch} skipped=${result.summary.skipped}`,
  )
  console.log(
    `Lost buckets: priority=${result.summary.lostPriority} review=${result.summary.lostReview} junk=${result.summary.lostJunk}`,
  )
  console.log('')

  for (const item of result.found) {
    const status = item.sizeStatus === 'mismatch' ? paint.yellow('RECOVERED SIZE MISMATCH') : paint.green('RECOVERED')
    console.log(`${status} #${item.fileId} ${item.relativePath}`)
  }

  for (const item of result.notFound) {
    console.log(`${lostStatusLabel(item, paint)} #${item.fileId} ${item.relativePath}`)
  }

  console.log('')
  console.log(`Recovered report: ${reportPaths.found}`)
  console.log(`Forever-lost report: ${reportPaths.notFound}`)
  console.log(`Size-mismatch report: ${reportPaths.sizeMismatches}`)
  console.log(`Lost priority report: ${reportPaths.lostPriority}`)
  console.log(`Lost review report: ${reportPaths.lostReview}`)
  console.log(`Lost junk report: ${reportPaths.lostJunk}`)
}

function colors(enabled) {
  const wrap = (code, value) => enabled ? `\u001B[${code}m${value}\u001B[0m` : value

  return {
    cyan: (value) => wrap(36, value),
    gray: (value) => wrap(90, value),
    green: (value) => wrap(32, value),
    red: (value) => wrap(31, value),
    yellow: (value) => wrap(33, value),
  }
}

function lostStatusLabel(item, paint) {
  if (item.lostBucket === 'priority') {
    return paint.red('FOREVER LOST PRIORITY')
  }

  if (item.lostBucket === 'junk') {
    return paint.gray('FOREVER LOST JUNK')
  }

  return paint.yellow('FOREVER LOST REVIEW')
}
