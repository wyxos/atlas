<?php

namespace App\Console\Commands;

use App\Models\FileMetadata;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixFileMetadataRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metadata:fix-records
                            {--dry-run : Show what would be fixed without making changes}
                            {--limit= : Limit the number of records to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix FileMetadata records that may have been affected by metadata misalignment';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('Scanning for FileMetadata records with potential misalignment issues...');

        // Find records that might have double-encoded JSON payload
        // These would be records where the payload is a JSON string instead of a JSON object
        // This happens when we manually json_encode() an array that gets auto-encoded by the array cast
        $query = FileMetadata::whereHas('file', fn($query) => $query->where('source', 'CivitAI'))->whereRaw('JSON_TYPE(payload) = "STRING"'); // Payload is a JSON string instead of object

        if ($limit) {
            $query->limit($limit);
        }

        $affectedRecords = $query->get();

        if ($affectedRecords->isEmpty()) {
            $this->info('✅ No records found that need fixing.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$affectedRecords->count()} records that may need fixing.");

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->table(
                ['ID', 'File ID', 'Payload Preview', 'Issue Type'],
                $affectedRecords->map(function ($record) {
                    return [
                        $record->id,
                        $record->file_id,
                        $this->getPayloadPreview($record->payload),
                        $this->getIssueType($record->payload),
                    ];
                })->toArray()
            );
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (!$this->confirm("Do you want to attempt to fix {$affectedRecords->count()} records?")) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        $fixed = 0;
        $failed = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar($affectedRecords->count());
        $bar->start();

        foreach ($affectedRecords as $record) {
            try {
                $result = $this->fixRecord($record);
                if ($result === 'fixed') {
                    $fixed++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                }
            } catch (Exception $e) {
                $failed++;
                $this->newLine();
                $this->error("Failed to fix record {$record->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Fixed: {$fixed} records");
        if ($skipped > 0) {
            $this->warn("⚠️  Skipped: {$skipped} records (no action needed)");
        }
        if ($failed > 0) {
            $this->error("❌ Failed: {$failed} records");
        }

        return Command::SUCCESS;
    }

    private function getPayloadPreview(string $payload): string
    {
        return strlen($payload) > 100 ? substr($payload, 0, 100) . '...' : $payload;
    }

    private function getIssueType(string $payload): string
    {
        $decodedOnce = json_decode($payload, true);

        if (!$decodedOnce) {
            return 'Invalid JSON';
        }

        // If it's a string, it might be double-encoded
        if (is_string($decodedOnce)) {
            $decodedTwice = json_decode($decodedOnce, true);
            if (is_array($decodedTwice)) {
                return 'Double-encoded JSON';
            }
        }

        return 'Unknown issue';
    }

    private function fixRecord(FileMetadata $record): string
    {
        // Get the raw payload from the database (this bypasses Laravel's array cast)
        $rawPayload = DB::table('file_metadata')
            ->where('id', $record->id)
            ->value('payload');

        // First decode - this should give us a JSON string if it's double-encoded
        $firstDecode = json_decode($rawPayload, true);

        if (!$firstDecode) {
            return 'skipped';
        }

        // If the first decode result is a string, try decoding again
        if (is_string($firstDecode)) {
            $secondDecode = json_decode($firstDecode, true);

            if (is_array($secondDecode)) {
                // This was double-encoded! Fix it by updating directly in the database
                // to bypass the array cast and store the properly decoded JSON
                DB::table('file_metadata')
                    ->where('id', $record->id)
                    ->update(['payload' => json_encode($secondDecode)]);

                return 'fixed';
            }
        }

        return 'skipped';
    }
}
