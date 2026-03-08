export type MimeTypeCategory = 'image' | 'video' | 'audio' | 'other' | 'unknown';

function normalizeMimeType(mimeType: string | null | undefined): string {
    return typeof mimeType === 'string' ? mimeType.trim().toLowerCase() : '';
}

export function isVideoMimeType(mimeType: string | null | undefined): boolean {
    const normalized = normalizeMimeType(mimeType);
    return normalized.startsWith('video/') || normalized === 'application/mp4';
}

export function formatFileSize(bytes: number | null): string {
    if (bytes === null || bytes === 0) {
        return '0 B';
    }

    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`;
}

export function getMimeTypeCategory(mimeType: string | null): MimeTypeCategory {
    const normalized = normalizeMimeType(mimeType);
    if (!normalized) {
        return 'unknown';
    }
    if (normalized.startsWith('image/')) {
        return 'image';
    }
    if (isVideoMimeType(normalized)) {
        return 'video';
    }
    if (normalized.startsWith('audio/')) {
        return 'audio';
    }
    return 'other';
}

export function getMimeTypeBadgeClasses(mimeType: string | null): string {
    const category = getMimeTypeCategory(mimeType);
    const classMap: Record<MimeTypeCategory, string> = {
        image: 'bg-blue-500/20 text-blue-300',
        video: 'bg-purple-500/20 text-purple-300',
        audio: 'bg-green-500/20 text-green-300',
        other: 'bg-twilight-indigo-500/20 text-twilight-indigo-100',
        unknown: 'bg-twilight-indigo-500/20 text-twilight-indigo-700',
    };
    return classMap[category];
}
