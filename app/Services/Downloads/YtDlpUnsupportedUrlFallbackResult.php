<?php

namespace App\Services\Downloads;

final readonly class YtDlpUnsupportedUrlFallbackResult
{
    /**
     * @param  list<string>  $domains
     */
    public function __construct(public array $domains) {}
}
