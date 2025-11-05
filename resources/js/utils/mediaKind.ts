export type MediaKind = 'video' | 'image'

const VIDEO_LIKE_EXTENSIONS = new Set<string>([
  'mp4',
  'webm',
  'mov',
  'mkv',
  'm4v',
  'avi',
  'gifv',
  'wmv',
  'flv',
  'mpg',
  'mpeg',
])

const IMAGE_LIKE_EXTENSIONS = new Set<string>([
  'jpg',
  'jpeg',
  'png',
  'gif',
  'webp',
  'bmp',
  'avif',
  'heic',
  'heif',
  'tiff',
  'svg',
])

const MEDIA_HINT_KEYS = ['format', 'type', 'ext', 'extension', 'contentType', 'content_type', 'mime', 'media', 'mediaType', 'media_type']

function normalise(value: string | null | undefined): string | null {
  if (typeof value !== 'string') {
    return null
  }
  const trimmed = value.trim()
  return trimmed.length ? trimmed.toLowerCase() : null
}

function extractExtension(fragment: string | null | undefined): string | null {
  const normalised = normalise(fragment)
  if (!normalised) {
    return null
  }

  const withoutQuery = normalised.split(/[?#]/)[0] ?? ''
  const lastSegment = withoutQuery.split('/').pop() ?? ''
  if (!lastSegment) {
    return null
  }

  const candidate = lastSegment.includes('.') ? lastSegment.split('.').pop() ?? '' : lastSegment
  return candidate.length ? candidate : null
}

function matchKindFromExtension(ext: string | null): MediaKind | null {
  if (!ext) {
    return null
  }
  const lowered = ext.toLowerCase()
  if (VIDEO_LIKE_EXTENSIONS.has(lowered)) {
    return 'video'
  }
  if (IMAGE_LIKE_EXTENSIONS.has(lowered)) {
    return 'image'
  }
  return null
}

function detectFromHint(rawHint: string | null | undefined): MediaKind | null {
  const hint = normalise(rawHint)
  if (!hint) {
    return null
  }

  if (hint.includes('video')) {
    return 'video'
  }
  if (hint.includes('image')) {
    return 'image'
  }

  const extFromHint = extractExtension(hint)
  return matchKindFromExtension(extFromHint) ?? matchKindFromExtension(hint)
}

export function detectMediaKind(rawUrl: string | null | undefined): MediaKind | null {
  const viaExtension = matchKindFromExtension(extractExtension(rawUrl))
  if (viaExtension) {
    return viaExtension
  }

  if (!rawUrl) {
    return null
  }

  try {
    const base = typeof window !== 'undefined' ? window.location.origin : 'http://localhost'
    const url = new URL(rawUrl, base)

    for (const key of MEDIA_HINT_KEYS) {
      const values = url.searchParams.getAll(key)
      for (const value of values) {
        const detected = detectFromHint(value)
        if (detected) {
          return detected
        }
      }
    }
  } catch {
    // ignore URL parsing errors for malformed strings
  }

  return null
}

export function resolveMediaKind(
  candidates: Array<string | null | undefined>,
  fallback: MediaKind | null,
): MediaKind | null {
  for (const candidate of candidates) {
    const detected = detectMediaKind(candidate)
    if (detected) {
      return detected
    }
  }

  return fallback ?? null
}

export const mediaKind = {
  detect: detectMediaKind,
  resolve: resolveMediaKind,
}

