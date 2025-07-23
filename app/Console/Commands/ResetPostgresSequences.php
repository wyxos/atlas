<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ResetPostgresSequences extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:reset-sequences';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset PostgreSQL sequences after data migration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Resetting PostgreSQL sequences...');

        $tables = [
            'users',
            'artists', 
            'albums',
            'files',
            'file_metadata',
            'covers',
            'playlists',
            'queues',
            'login_histories',
        ];

        try {
            foreach ($tables as $table) {
                $sequenceName = "{$table}_id_seq";
                
                // Get the maximum ID from the table
                $maxId = \DB::table($table)->max('id') ?: 0;
                $nextId = $maxId + 1;
                
                // Reset the sequence to start from the next available ID
                \DB::statement("SELECT setval('{$sequenceName}', {$nextId}, false)");
                
                $this->info("✓ Reset {$sequenceName} to start from {$nextId}");
            }
            
            $this->info('\nAll sequences reset successfully!');
            
        } catch (\Exception $e) {
            $this->error('Failed to reset sequences: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
