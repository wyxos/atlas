import { describe, it, expect } from 'vitest'

import { detectMediaKind, resolveMediaKind } from '@/utils/mediaKind'

describe('mediaKind detection', () => {
  it('detects video by extension', () => {
    expect(detectMediaKind('https://example.com/video.MP4')).toBe('video')
  })

  it('detects image by extension', () => {
    expect(detectMediaKind('https://cdn.example.com/thumb.jpeg')).toBe('image')
  })

  it('detects video using query hints', () => {
    expect(detectMediaKind('https://media.example.com/render?id=1&format=webm')).toBe('video')
  })

  it('detects image using MIME style query values', () => {
    expect(detectMediaKind('https://cdn.example.com/file?contentType=image/png')).toBe('image')
  })

  it('returns null when no hints exist', () => {
    expect(detectMediaKind('https://example.com/file/download')).toBeNull()
  })
})

describe('mediaKind resolve helper', () => {
  it('returns first detected kind from candidates', () => {
    const result = resolveMediaKind([
      'https://example.com/download',
      'https://cdn.example.com/file?format=mp4',
    ], 'image')

    expect(result).toBe('video')
  })

  it('falls back to provided fallback when nothing is detected', () => {
    const result = resolveMediaKind(['https://example.com/path'], 'image')
    expect(result).toBe('image')
  })
})

