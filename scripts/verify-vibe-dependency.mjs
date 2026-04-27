#!/usr/bin/env node

import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, realpathSync } from 'node:fs'
import path from 'node:path'

const cwd = process.cwd()
const packageJson = JSON.parse(readFileSync('package.json', 'utf8'))
const dependencies = {
  ...packageJson.dependencies,
  ...packageJson.devDependencies,
  ...packageJson.peerDependencies,
}
const declaredSpec = dependencies['@wyxos/vibe']

if (!declaredSpec) {
  console.error('package.json does not declare @wyxos/vibe.')
  process.exit(1)
}

function runNpm(args) {
  const npmCli = resolveNpmCli()

  if (npmCli) {
    return execFileSync(process.execPath, [npmCli, ...args], {
      cwd,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    }).trim()
  }

  return execFileSync('npm', args, {
    cwd,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim()
}

function resolveNpmCli() {
  if (process.env.npm_execpath && existsSync(process.env.npm_execpath)) {
    return process.env.npm_execpath
  }

  const siblingCli = path.join(
    path.dirname(process.execPath),
    'node_modules',
    'npm',
    'bin',
    'npm-cli.js',
  )

  if (existsSync(siblingCli)) {
    return siblingCli
  }

  return null
}

function npmView(target, field) {
  const output = runNpm(['view', target, field, '--json'])

  return JSON.parse(output)
}

function run(command, args) {
  try {
    return execFileSync(command, args, {
      cwd,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim()
  }
  catch {
    return ''
  }
}

function normalizeSpec(spec) {
  if (spec.startsWith('npm:@wyxos/vibe@')) {
    return spec.slice('npm:@wyxos/vibe@'.length)
  }

  return spec
}

const npmSpec = normalizeSpec(declaredSpec)

if (/^(?:file|link|workspace):/.test(npmSpec)) {
  console.error(`@wyxos/vibe uses a local-only spec (${declaredSpec}).`)
  console.error('Atlas production should depend on a published npm semver range.')
  process.exit(1)
}

let resolvedVersion
let latestVersion

try {
  resolvedVersion = npmView(`@wyxos/vibe@${npmSpec}`, 'version')
  latestVersion = npmView('@wyxos/vibe', 'version')
}
catch (error) {
  console.error(`No published @wyxos/vibe version resolves for ${declaredSpec}.`)

  if (error instanceof Error && error.message) {
    console.error(error.message)
  }

  process.exit(1)
}

const installedPackageJson = path.join(cwd, 'node_modules', '@wyxos', 'vibe', 'package.json')

console.log(`package.json declares @wyxos/vibe: ${declaredSpec}`)
console.log(`npm resolves that range to @wyxos/vibe@${resolvedVersion}`)
console.log(`npm latest is @wyxos/vibe@${latestVersion}`)

if (existsSync(installedPackageJson)) {
  const installed = JSON.parse(readFileSync(installedPackageJson, 'utf8'))
  const installedRoot = realpathSync(path.dirname(installedPackageJson))

  console.log(`local node_modules has @wyxos/vibe@${installed.version}`)

  if (!installedRoot.includes(`${path.sep}node_modules${path.sep}`)) {
    console.log(`local @wyxos/vibe is linked from ${installedRoot}`)
  }

  if (installed.version !== resolvedVersion) {
    console.log(
      'local node_modules does not match npm resolution; run npm update @wyxos/vibe when local verification must use the published package.',
    )
  }
}
else {
  console.log('local node_modules does not currently contain @wyxos/vibe.')
}

const packageLockTracked = run('git', ['ls-files', '--error-unmatch', 'package-lock.json']) !== ''

if (!packageLockTracked && existsSync(path.join(cwd, 'package-lock.json'))) {
  console.log(
    'package-lock.json exists locally but is not tracked; treat it as machine-local state, not production release truth.',
  )
}

console.log('Vibe dependency is backed by a published npm package.')
