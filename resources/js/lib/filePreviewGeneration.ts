import type { File, FilePreviewGeneration } from '@/types/file';

export function blocksDownloadedPreviewFallback(file: {
    downloaded?: boolean;
    preview_generation?: FilePreviewGeneration | null;
}): boolean {
    return file.downloaded === true
        && file.preview_generation !== null
        && file.preview_generation !== undefined
        && file.preview_generation.status !== 'ready';
}

export function resolveFilePreviewUrl(
    file: File,
    mediaKind: 'image' | 'video' | 'audio' | 'file',
    fallback: string | null = null,
): string {
    const generatedPreview = (mediaKind === 'audio' ? file.cover_url : null)
        ?? file.preview_file_url
        ?? file.preview_url
        ?? file.poster_url;

    if (generatedPreview) {
        return generatedPreview;
    }

    if (blocksDownloadedPreviewFallback(file)) {
        return '';
    }

    return file.file_url
        ?? file.disk_url
        ?? file.url
        ?? fallback
        ?? '';
}
