export type MimeTypeCategory = 'image' | 'video' | 'audio' | 'other' | 'unknown';

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
    if (!mimeType) {
        return 'unknown';
    }
    if (mimeType.startsWith('image/')) {
        return 'image';
    }
    if (mimeType.startsWith('video/')) {
        return 'video';
    }
    if (mimeType.startsWith('audio/')) {
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

