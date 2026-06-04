import { markCorrupted, redownload } from '@/actions/App/Http/Controllers/FilesController';
import type { File } from '@/types/file';

type FileRedownloadResponse = { queued: boolean; not_found: boolean; file: File };

export function useFileRedownloadActions(params: {
    applyFilters: () => Promise<void>;
    closeFileSheet: () => void;
    setFileData: (file: File) => void;
}) {
    async function handleFileRedownload(fileId: number): Promise<void> {
        try {
            const { data } = await window.axios.post<FileRedownloadResponse>(redownload.url(fileId));

            params.setFileData(data.file);
        } catch (error) {
            console.error('Failed to re-download file:', error);
        }
    }

    async function handleMarkCorruptedFile(fileId: number): Promise<void> {
        if (!window.confirm('Mark this downloaded file as corrupted and remove it from Atlas?')) {
            return;
        }

        try {
            await window.axios.delete(markCorrupted.url(fileId));
            params.closeFileSheet();
            await params.applyFilters();
        } catch (error) {
            console.error('Failed to mark file as corrupted:', error);
        }
    }

    return {
        handleFileRedownload,
        handleMarkCorruptedFile,
    };
}
