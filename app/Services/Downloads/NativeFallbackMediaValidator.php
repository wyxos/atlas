<?php

namespace App\Services\Downloads;

final class NativeFallbackMediaValidator
{
    public const int SNIFF_BYTES = 65536;

    public const string HTML_REJECTION = 'Native fallback returned an HTML page instead of media.';

    public const string INSPECTION_REJECTION = 'Unable to validate the native fallback response.';

    public function rejectionForContentType(?string $contentTypeHeader): ?string
    {
        $contentType = strtolower(trim(explode(';', (string) $contentTypeHeader, 2)[0]));

        return in_array($contentType, ['text/html', 'application/xhtml+xml'], true)
            ? self::HTML_REJECTION
            : null;
    }

    public function rejectionForArtifact(string $absolutePath, ?string $contentTypeHeader = null): ?string
    {
        $contentTypeRejection = $this->rejectionForContentType($contentTypeHeader);
        if ($contentTypeRejection !== null) {
            return $contentTypeRejection;
        }

        $handle = @fopen($absolutePath, 'rb');
        if (! is_resource($handle)) {
            return self::INSPECTION_REJECTION;
        }

        try {
            $prefix = fread($handle, self::SNIFF_BYTES);
        } finally {
            fclose($handle);
        }

        if (! is_string($prefix)) {
            return self::INSPECTION_REJECTION;
        }

        if ($prefix === '') {
            return self::INSPECTION_REJECTION;
        }

        return $this->looksLikeHtml($prefix) ? self::HTML_REJECTION : null;
    }

    private function looksLikeHtml(string $prefix): bool
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            try {
                $detectedType = finfo_buffer($finfo, $prefix) ?: null;
            } finally {
                finfo_close($finfo);
            }

            if (in_array($detectedType, ['text/html', 'application/xhtml+xml'], true)) {
                return true;
            }
        }

        if (str_starts_with($prefix, "\xEF\xBB\xBF")) {
            $prefix = substr($prefix, 3);
        }

        $prefix = ltrim($prefix);
        while (preg_match('/\A<!--.*?-->\s*/is', $prefix, $match) === 1) {
            $prefix = substr($prefix, strlen($match[0]));
        }

        if (preg_match('/\A<\?xml\b.{0,4096}<html\b/is', $prefix) === 1) {
            return true;
        }

        return preg_match('/\A<(?:!doctype\s+html\b|html\b|head\b|body\b|script\b|meta\b|title\b|form\b)/i', $prefix) === 1;
    }
}
