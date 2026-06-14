import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import {
  auditLegacyRows,
  classifyLostFile,
  csvLine,
  relativePathFromMarker,
  writeReports,
} from './audit-recovered-downloads.mjs'

const roots = []

afterEach(() => {
  for (const root of roots.splice(0)) {
    rmSync(root, { force: true, recursive: true })
  }
})

describe('audit recovered downloads', () => {
  it('derives relative paths from legacy Windows and Linux paths', () => {
    expect(relativePathFromMarker('F:\\0000 - Downloads\\Artist\\track.mp3')).toBe('Artist/track.mp3')
    expect(relativePathFromMarker('/mnt/atlas/0000 - Downloads/Missing/lost.mp3')).toBe('Missing/lost.mp3')
  })

  it('classifies legacy rows found in the recovered folder and rows not found there', () => {
    const recoveredRoot = makeTempDirectory('atlas-recovered-')
    mkdirSync(path.join(recoveredRoot, 'Artist'), { recursive: true })
    writeFileSync(path.join(recoveredRoot, 'Artist', 'track.mp3'), 'restored')

    const result = auditLegacyRows([
      {
        id: 10,
        path: 'F:\\0000 - Downloads\\Artist\\track.mp3',
        size: 8,
        hash: 'abc',
        source: 'local',
      },
      {
        id: 11,
        path: '/mnt/atlas/0000 - Downloads/Missing/lost.mp3',
        size: 4,
        hash: null,
        source: 'local',
      },
    ], { recoveredRoot })

    expect(result.summary).toMatchObject({
      total: 2,
      found: 1,
      notFound: 1,
      lostPriority: 1,
      lostReview: 0,
      lostJunk: 0,
      sameSize: 1,
      sizeMismatch: 0,
    })
    expect(result.found[0]).toMatchObject({
      fileId: 10,
      relativePath: 'Artist/track.mp3',
      expectedSize: 8,
      recoveredSize: 8,
      sizeStatus: 'same',
    })
    expect(result.notFound[0]).toMatchObject({
      fileId: 11,
      relativePath: 'Missing/lost.mp3',
      lostBucket: 'priority',
      lostReason: 'audio',
      extension: '.mp3',
    })
  })

  it('classifies forever-lost files into priority, review, and junk buckets', () => {
    expect(classifyLostFile('Music/song.MP3')).toMatchObject({
      bucket: 'priority',
      extension: '.mp3',
      reason: 'audio',
    })
    expect(classifyLostFile('Videos/clip.mp4')).toMatchObject({
      bucket: 'priority',
      reason: 'video',
    })
    expect(classifyLostFile('Archives/backup.zip')).toMatchObject({
      bucket: 'priority',
      reason: 'archive',
    })
    expect(classifyLostFile('Album/Thumbs.db')).toMatchObject({
      bucket: 'junk',
      reason: 'system-metadata',
    })
    expect(classifyLostFile('Album/._cover.jpg')).toMatchObject({
      bucket: 'junk',
      reason: 'macos-resource-fork',
    })
    expect(classifyLostFile('Release/file.nfo')).toMatchObject({
      bucket: 'junk',
      reason: 'release-metadata',
    })
    expect(classifyLostFile('Album/cover.jpg')).toMatchObject({
      bucket: 'review',
      reason: 'image-or-artwork',
    })
    expect(classifyLostFile('Album/disc.cue')).toMatchObject({
      bucket: 'review',
      reason: 'disc-or-rip-support',
    })
    expect(classifyLostFile('Other/custom.mxm')).toMatchObject({
      bucket: 'review',
      reason: 'unknown-extension',
    })
  })

  it('writes found and forever-lost csv reports', () => {
    const reportRoot = makeTempDirectory('atlas-reports-')
    const reportPaths = writeReports(reportRoot, {
      found: [{
        fileId: 10,
        relativePath: 'Artist/track.mp3',
        legacyPath: 'F:\\0000 - Downloads\\Artist\\track.mp3',
        recoveredPath: 'D:\\Recovered\\Files(F)\\0000 - Downloads\\Artist\\track.mp3',
        expectedSize: 8,
        recoveredSize: 8,
        sizeStatus: 'same',
        storedHash: 'abc',
      }],
      notFound: [{
        fileId: 11,
        relativePath: 'Missing/lost.mp3',
        legacyPath: '/mnt/atlas/0000 - Downloads/Missing/lost.mp3',
        extension: '.mp3',
        lostBucket: 'priority',
        lostReason: 'audio',
        expectedSize: 4,
        storedHash: null,
      }, {
        fileId: 12,
        relativePath: 'Album/Thumbs.db',
        legacyPath: '/mnt/atlas/0000 - Downloads/Album/Thumbs.db',
        extension: '.db',
        lostBucket: 'junk',
        lostReason: 'system-metadata',
        expectedSize: 1024,
        storedHash: null,
      }, {
        fileId: 13,
        relativePath: 'Album/cover.jpg',
        legacyPath: '/mnt/atlas/0000 - Downloads/Album/cover.jpg',
        extension: '.jpg',
        lostBucket: 'review',
        lostReason: 'image-or-artwork',
        expectedSize: 4,
        storedHash: null,
      }],
      lostPriority: [{
        fileId: 11,
        relativePath: 'Missing/lost.mp3',
        legacyPath: '/mnt/atlas/0000 - Downloads/Missing/lost.mp3',
        extension: '.mp3',
        lostBucket: 'priority',
        lostReason: 'audio',
        expectedSize: 4,
        storedHash: null,
      }],
      lostReview: [{
        fileId: 13,
        relativePath: 'Album/cover.jpg',
        legacyPath: '/mnt/atlas/0000 - Downloads/Album/cover.jpg',
        extension: '.jpg',
        lostBucket: 'review',
        lostReason: 'image-or-artwork',
        expectedSize: 4,
        storedHash: null,
      }],
      lostJunk: [{
        fileId: 12,
        relativePath: 'Album/Thumbs.db',
        legacyPath: '/mnt/atlas/0000 - Downloads/Album/Thumbs.db',
        extension: '.db',
        lostBucket: 'junk',
        lostReason: 'system-metadata',
        expectedSize: 1024,
        storedHash: null,
      }],
      sizeMismatches: [],
    })

    expect(readFileSync(reportPaths.found, 'utf8')).toContain(csvLine([
      10,
      'Artist/track.mp3',
      'F:\\0000 - Downloads\\Artist\\track.mp3',
      'D:\\Recovered\\Files(F)\\0000 - Downloads\\Artist\\track.mp3',
      8,
      8,
      'same',
      'abc',
    ]))
    expect(readFileSync(reportPaths.notFound, 'utf8')).toContain(csvLine([
      11,
      'Missing/lost.mp3',
      '/mnt/atlas/0000 - Downloads/Missing/lost.mp3',
      '.mp3',
      'priority',
      'audio',
      4,
      '',
    ]))
    expect(readFileSync(reportPaths.lostPriority, 'utf8')).toContain('Missing/lost.mp3')
    expect(readFileSync(reportPaths.lostReview, 'utf8')).toContain('Album/cover.jpg')
    expect(readFileSync(reportPaths.lostJunk, 'utf8')).toContain('Album/Thumbs.db')
  })
})

function makeTempDirectory(prefix) {
  const root = mkdtempSync(path.join(tmpdir(), prefix))
  roots.push(root)

  return root
}
