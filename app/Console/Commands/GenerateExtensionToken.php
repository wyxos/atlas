<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateExtensionToken extends Command
{
    protected $signature = 'atlas:extension-token {--set : Write token to .env} {--force : Regenerate even if one exists}';

    protected $description = 'Generate a token for the Atlas browser extension';

    public function handle(): int
    {
        $existing = (string) config('downloads.extension_token');
        $shouldForce = (bool) $this->option('force');

        if ($existing !== '' && ! $shouldForce) {
            $this->info('Existing ATLAS_EXTENSION_TOKEN found:');
            $this->line($existing);
            $this->line('Use --force to regenerate.');

            return self::SUCCESS;
        }

        $token = Str::random(48);
        $this->info('Generated ATLAS_EXTENSION_TOKEN:');
        $this->line($token);

        if (! $this->option('set')) {
            $this->line('Run with --set to persist this token to .env.');

            return self::SUCCESS;
        }

        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            $this->error('No .env file found. Please add the token manually.');

            return self::FAILURE;
        }

        $updated = $this->updateEnvValue($envPath, 'ATLAS_EXTENSION_TOKEN', $token);

        if ($updated) {
            $this->info('Token written to .env.');
            $this->line('If config is cached, run: php artisan config:clear');
        } else {
            $this->error('Failed to update .env. Please add the token manually.');
        }

        return $updated ? self::SUCCESS : self::FAILURE;
    }

    private function updateEnvValue(string $envPath, string $key, string $value): bool
    {
        $contents = (string) file_get_contents($envPath);

        if (preg_match("/^{$key}=.*$/m", $contents)) {
            $contents = preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $contents) ?? $contents;
        } else {
            $contents = rtrim($contents).PHP_EOL."{$key}={$value}".PHP_EOL;
        }

        return file_put_contents($envPath, $contents) !== false;
    }
}
