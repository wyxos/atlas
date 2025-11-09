<?php

namespace App\Services;

use App\Models\File;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class UrlResolver
{
    public function __construct(private readonly int $fileId) {}

    /**
     * Resolve the original media URL by scraping the file's referrer page.
     */
    public function resolve(): ?string
    {
        $file = File::query()->find($this->fileId);

        if (! $file) {
            return null;
        }

        $referrer = (string) $file->referrer_url;

        if ($referrer === '') {
            return null;
        }

        try {
            $response = Http::get($referrer);
        } catch (ConnectionException|RequestException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $html = $response->body();
        $loaded = false;

        try {
            $loaded = $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//video//source[@type="video/mp4"][@src]');

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $source = trim((string) $nodes->item(0)?->getAttribute('src'));

        if ($source === '' || ! str_contains($source, '.mp4')) {
            return null;
        }

        return $source;
    }
}
