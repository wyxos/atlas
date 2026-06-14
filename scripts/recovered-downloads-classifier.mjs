import path from 'node:path'

const PRIORITY_EXTENSIONS = new Map([
  ['.aac', 'audio'],
  ['.aif', 'audio'],
  ['.aiff', 'audio'],
  ['.alac', 'audio'],
  ['.ape', 'audio'],
  ['.flac', 'audio'],
  ['.m4a', 'audio'],
  ['.mp3', 'audio'],
  ['.ogg', 'audio'],
  ['.opus', 'audio'],
  ['.wav', 'audio'],
  ['.wma', 'audio'],
  ['.3gp', 'video'],
  ['.avi', 'video'],
  ['.flv', 'video'],
  ['.m2ts', 'video'],
  ['.m4v', 'video'],
  ['.mkv', 'video'],
  ['.mov', 'video'],
  ['.mp4', 'video'],
  ['.mpeg', 'video'],
  ['.mpg', 'video'],
  ['.ts', 'video'],
  ['.webm', 'video'],
  ['.wmv', 'video'],
  ['.7z', 'archive'],
  ['.bz2', 'archive'],
  ['.gz', 'archive'],
  ['.rar', 'archive'],
  ['.tar', 'archive'],
  ['.tgz', 'archive'],
  ['.xz', 'archive'],
  ['.zip', 'archive'],
])
const REVIEW_EXTENSIONS = new Map([
  ['.ass', 'subtitle'],
  ['.avif', 'image-or-artwork'],
  ['.bmp', 'image-or-artwork'],
  ['.cue', 'disc-or-rip-support'],
  ['.doc', 'document'],
  ['.docx', 'document'],
  ['.gif', 'image-or-artwork'],
  ['.heic', 'image-or-artwork'],
  ['.idx', 'subtitle'],
  ['.jpeg', 'image-or-artwork'],
  ['.jpg', 'image-or-artwork'],
  ['.log', 'disc-or-rip-support'],
  ['.m3u', 'playlist'],
  ['.m3u8', 'playlist'],
  ['.pdf', 'document'],
  ['.pls', 'playlist'],
  ['.png', 'image-or-artwork'],
  ['.rtf', 'document'],
  ['.srt', 'subtitle'],
  ['.ssa', 'subtitle'],
  ['.sub', 'subtitle'],
  ['.tif', 'image-or-artwork'],
  ['.tiff', 'image-or-artwork'],
  ['.txt', 'document'],
  ['.vtt', 'subtitle'],
  ['.webp', 'image-or-artwork'],
  ['.xspf', 'playlist'],
])
const JUNK_EXTENSIONS = new Map([
  ['.ini', 'system-metadata'],
  ['.nfo', 'release-metadata'],
  ['.plist', 'system-metadata'],
  ['.sfv', 'release-metadata'],
  ['.torrent', 'download-pointer'],
  ['.url', 'shortcut'],
])
const SYSTEM_METADATA_FILENAMES = new Set(['.ds_store', 'desktop.ini', 'thumbs.db'])

export function classifyLostFile(filePath) {
  const fileName = fileNameFromPath(filePath)
  const lowerName = fileName.toLowerCase()
  const extension = path.posix.extname(lowerName)

  if (lowerName.startsWith('._')) {
    return { bucket: 'junk', extension, reason: 'macos-resource-fork' }
  }

  if (SYSTEM_METADATA_FILENAMES.has(lowerName)) {
    return { bucket: 'junk', extension, reason: 'system-metadata' }
  }

  if (isAlbumArtCacheFile(lowerName)) {
    return { bucket: 'junk', extension, reason: 'album-art-cache' }
  }

  const junkReason = JUNK_EXTENSIONS.get(extension)
  if (junkReason) {
    return { bucket: 'junk', extension, reason: junkReason }
  }

  const priorityReason = PRIORITY_EXTENSIONS.get(extension)
  if (priorityReason) {
    return { bucket: 'priority', extension, reason: priorityReason }
  }

  const reviewReason = REVIEW_EXTENSIONS.get(extension)
  if (reviewReason) {
    return { bucket: 'review', extension, reason: reviewReason }
  }

  return {
    bucket: 'review',
    extension,
    reason: extension === '' ? 'no-extension' : 'unknown-extension',
  }
}

function fileNameFromPath(filePath) {
  const normalized = String(filePath ?? '').replaceAll('\\', '/')

  return normalized.split('/').pop() ?? ''
}

function isAlbumArtCacheFile(lowerName) {
  return lowerName === 'albumartsmall.jpg'
    || /^albumart_[0-9a-f-]+_(large|small)\.jpe?g$/i.test(lowerName)
}
