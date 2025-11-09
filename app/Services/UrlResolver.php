<?php

namespace App\Services;

use App\Models\File;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UrlResolver
{
    public function __construct(private readonly int $fileId) {}

    /**
     * Resolve the original media URL by scraping the file's referrer page.
     *
     * @throws RequestException|\Illuminate\Http\Client\ConnectionException
     */
    public function resolve(): string
    {
        $file = File::query()->findOrFail($this->fileId);

        $referrer = (string) $file->referrer_url;

        if ($referrer === '') {
            throw new RuntimeException("File {$file->id} does not have a referrer URL to resolve against.");
        }

        $response = Http::get($referrer);

        $response->throw();

        $html = $response->body();

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//video//source[@type="video/mp4"][@src]');

        if ($nodes === false || $nodes->length === 0) {
            throw new RuntimeException("Unable to locate MP4 source for file {$file->id} at {$referrer}.");
        }

        $source = trim((string) $nodes->item(0)?->getAttribute('src'));

        if ($source === '' || ! str_contains($source, '.mp4')) {
            throw new RuntimeException("Resolved MP4 source was empty for file {$file->id} at {$referrer}.");
        }

        return $source;
    }
}
