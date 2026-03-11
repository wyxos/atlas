<?php

namespace App\Console\Commands;

use App\Jobs\NormalizeReferrerUrls as NormalizeReferrerUrlsJob;
use App\Support\ReferrerUrlCleanup;
use Illuminate\Console\Command;

class NormalizeReferrerUrls extends Command
{
    protected $signature = 'atlas:normalize-referrer-urls
        {domain : Hostname to normalize (subdomains included)}
        {--strip-query-param=* : Query parameter names to strip, or "*" to strip all query params}
        {--chunk=500 : Number of matching files to process per queued job}
        {--queue=processing : Queue name to dispatch jobs to}
        {--start-id=0 : Resume scanning after this file ID}';

    protected $description = 'Queue a backfill that normalizes file referrer URLs for a specific domain';

    public function handle(): int
    {
        $domain = ReferrerUrlCleanup::normalizeDomain((string) $this->argument('domain'));
        if ($domain === null) {
            $this->error('The domain argument must be a valid hostname or URL.');

            return self::FAILURE;
        }

        $queryParamsToStrip = ReferrerUrlCleanup::normalizeQueryParams((array) $this->option('strip-query-param'));
        if ($queryParamsToStrip === []) {
            $this->error('Provide at least one --strip-query-param option, or use --strip-query-param=* to remove all query params.');

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $queue = trim((string) $this->option('queue'));
        $startId = max(0, (int) $this->option('start-id'));

        if ($queue === '') {
            $queue = 'processing';
        }

        NormalizeReferrerUrlsJob::dispatch($domain, $queryParamsToStrip, $startId, $chunk, $queue);

        $queryParamsSummary = implode(', ', $queryParamsToStrip);
        $this->info(
            "Queued referrer URL normalization for {$domain} from file id > {$startId} ".
            "with strip-query-param={$queryParamsSummary}, chunk={$chunk}, queue={$queue}."
        );

        return self::SUCCESS;
    }
}
