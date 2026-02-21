export interface DownloadTransferFileSummary {
    id: number;
    filename: string;
}

export interface DownloadTransfer {
    id: number;
    file_id: number;
    domain: string;
    url: string;
    status: string;
    percent: number;
    bytes_total: number | null;
    bytes_downloaded: number;
    created_at: string | null;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    failed_at: string | null;
    error: string | null;
    file: DownloadTransferFileSummary | null;
}

export interface DownloadTransferSummary {
    id: number;
    status: string;
    percent: number;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    error?: string | null;
}
