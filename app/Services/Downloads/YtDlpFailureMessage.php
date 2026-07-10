<?php

namespace App\Services\Downloads;

final class YtDlpFailureMessage
{
    public static function isUnsupportedUrl(string $message): bool
    {
        return preg_match('/(?:^|\R)\h*ERROR:\h*(?:\[generic\]\h*)?Unsupported URL(?::|\h*$)/iu', $message) === 1;
    }

    public static function normalize(string $message): string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return 'yt-dlp failed.';
        }

        $lower = strtolower($trimmed);
        $safeMessage = DownloadFailureMessage::normalize($trimmed, 'yt-dlp failed.');
        if (
            str_contains($lower, 'no video could be found in this tweet')
            || str_contains($lower, 'requested tweet may only be available for registered users')
        ) {
            return $safeMessage.' Ensure the Atlas extension is running on a logged-in page so auth cookies can be attached for this download.';
        }

        if (self::shouldDiscardTempArtifacts($trimmed)) {
            return $safeMessage.' Atlas discarded the temporary yt-dlp fragments for this transfer. Use Restart to fetch the file from scratch.';
        }

        return $safeMessage;
    }

    public static function shouldDiscardTempArtifacts(string $message): bool
    {
        $lower = strtolower(trim($message));
        if ($lower === '') {
            return false;
        }

        return str_contains($lower, '.ytdl file is corrupt')
            || str_contains($lower, 'downloaded file is empty')
            || (
                str_contains($lower, 'unable to rename file')
                && str_contains($lower, '.part-frag')
            );
    }
}
