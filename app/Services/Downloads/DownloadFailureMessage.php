<?php

namespace App\Services\Downloads;

final class DownloadFailureMessage
{
    public static function normalize(string $message, string $fallback = 'Download failed.'): string
    {
        $message = trim($message);
        if ($message === '') {
            return $fallback;
        }

        $redacted = preg_replace(
            '~(?<![A-Za-z0-9+.-])(?:[A-Za-z][A-Za-z0-9+.-]*:(?://)?[^\s<>"\']+|//[^\s<>"\']+|www\.[^\s<>"\']+)~i',
            '[redacted URL]',
            $message,
        );

        if (! is_string($redacted)) {
            return $fallback;
        }

        $redactedHosts = preg_replace(
            '~(?<![A-Za-z0-9.-])(?:(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}|(?:\d{1,3}\.){3}\d{1,3}|\[[0-9A-Fa-f:]{2,}\])(?::\d{1,5})?(?![A-Za-z0-9.-])~i',
            '[redacted host]',
            $redacted,
        );

        return is_string($redactedHosts) ? $redactedHosts : $fallback;
    }
}
