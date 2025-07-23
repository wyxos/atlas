<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanInvalidJsonData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:clean-json';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean invalid JSON data from file_metadata payload';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Cleaning invalid JSON data from file_metadata...');

        $count = 0;
        $fixed = 0;
        $errors = 0;

        // Process in chunks to avoid memory issues
        \DB::table('file_metadata')->orderBy('id')->chunk(100, function ($metadataRecords) use (&$count, &$fixed, &$errors) {
            foreach ($metadataRecords as $metadata) {
                $count++;
                
                if (!$metadata->payload) {
                    continue;
                }

                try {
                    // Decode the JSON payload
                    $payload = json_decode($metadata->payload, true);
                    
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->warn("Invalid JSON in metadata ID {$metadata->id}: " . json_last_error_msg());
                        continue;
                    }

                    // Clean up the payload by removing null bytes and other invalid characters
                    $cleanedPayload = $this->cleanJsonPayload($payload);
                    
                    // Re-encode the cleaned payload
                    $cleanedJson = json_encode($cleanedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    
                    if ($cleanedJson !== $metadata->payload) {
                        // Update the record with cleaned JSON
                        \DB::table('file_metadata')
                            ->where('id', $metadata->id)
                            ->update(['payload' => $cleanedJson]);
                        
                        $fixed++;
                        $this->info("Fixed metadata ID {$metadata->id}");
                    }
                    
                } catch (\Exception $e) {
                    $errors++;
                    $this->error("Error processing metadata ID {$metadata->id}: " . $e->getMessage());
                }
            }
        });

        $this->info("\nProcessing complete:");
        $this->info("- Total records processed: {$count}");
        $this->info("- Records fixed: {$fixed}");
        $this->info("- Errors: {$errors}");

        return 0;
    }

    /**
     * Clean JSON payload by removing invalid characters
     */
    private function cleanJsonPayload($payload)
    {
        if (is_array($payload)) {
            $cleaned = [];
            foreach ($payload as $key => $value) {
                $cleanKey = $this->cleanString($key);
                $cleanValue = is_array($value) ? $this->cleanJsonPayload($value) : $this->cleanString($value);
                $cleaned[$cleanKey] = $cleanValue;
            }
            return $cleaned;
        }
        
        return $this->cleanString($payload);
    }

    /**
     * Clean a string value by removing null bytes and other problematic characters
     */
    private function cleanString($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        // Remove null bytes and other control characters except newlines and tabs
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        
        // Remove any remaining invalid UTF-8 sequences
        $cleaned = mb_convert_encoding($cleaned, 'UTF-8', 'UTF-8');
        
        return $cleaned;
    }
}
