import type { AudioMetadataProposal, AudioMetadataRun } from '@/types/audio';

export function metadataRunTerminalMessage(run: AudioMetadataRun, proposal: AudioMetadataProposal | null): string | null {
    if (run.status !== 'completed') {
        return null;
    }

    if (proposal) {
        return 'Metadata proposal ready for review.';
    }

    if (run.failed_files > 0) {
        return 'Metadata lookup failed.';
    }

    return 'No metadata changes found.';
}

export function metadataRunProgressMessage(run: AudioMetadataRun): string {
    const label = typeof run.current_step_label === 'string' ? run.current_step_label.trim() : '';
    const total = Math.max(0, run.total_files);

    if (label !== '') {
        return total > 1
            ? `${label} (${run.processed_files}/${total})...`
            : `${label}...`;
    }

    if (total <= 0) {
        return 'Scanning metadata...';
    }

    return `Scanning metadata ${run.processed_files}/${total}...`;
}

export function batchMetadataRunMessage(run: AudioMetadataRun): string {
    const progressLabel = metadataRunFilesProgressLabel(run);

    if (run.status === 'completed') {
        const proposalSummary = run.proposal_count === 1
            ? ' 1 proposal ready.'
            : run.proposal_count > 1
                ? ` ${run.proposal_count} proposals ready.`
                : '';

        return `Metadata scan completed: ${progressLabel}.${proposalSummary}`;
    }

    if (run.status === 'failed') {
        return `Metadata scan failed: ${progressLabel}.`;
    }

    if (run.status === 'paused') {
        return `Metadata scan paused: ${progressLabel}.`;
    }

    if (run.status === 'canceled') {
        return `Metadata scan canceled: ${progressLabel}.`;
    }

    if (run.status === 'pending') {
        return `Metadata scan queued: ${progressLabel}.`;
    }

    return `Metadata scan running: ${progressLabel}.`;
}

export function metadataRunFilesProgressLabel(run: AudioMetadataRun): string {
    const processed = Math.max(0, run.processed_files);
    const total = Math.max(0, run.total_files);

    return `${processed}/${total} files (${metadataRunProgressPercent(run)}%)`;
}

export function metadataRunProgressPercent(run: AudioMetadataRun): number {
    const total = Math.max(0, run.total_files);

    if (total === 0) {
        return isMetadataRunTerminal(run) ? 100 : 0;
    }

    return Math.min(100, Math.max(0, Math.round((Math.max(0, run.processed_files) / total) * 100)));
}

export function isMetadataRunTerminal(run: AudioMetadataRun): boolean {
    return ['completed', 'failed', 'canceled'].includes(run.status);
}
