<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Exception;

class MigrateSqliteToPostgres extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-sqlite-to-postgres';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate data from SQLite to PostgreSQL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting migration from SQLite to PostgreSQL...');

        try {
            // Test PostgreSQL connection
            $postgresConnection = DB::connection('pgsql');
            $postgresConnection->getPdo();
            $this->info('✓ PostgreSQL connection established');
        } catch (Exception $e) {
            $this->error('Failed to connect to PostgreSQL: ' . $e->getMessage());
            return 1;
        }

        $models = [
            'users' => \App\Models\User::class,
            'artists' => \App\Models\Artist::class,
            'albums' => \App\Models\Album::class,
            'files' => \App\Models\File::class,
            'file_metadata' => \App\Models\FileMetadata::class,
            'covers' => \App\Models\Cover::class,
            'playlists' => \App\Models\Playlist::class,
            'queues' => \App\Models\Queue::class,
            'login_histories' => \App\Models\LoginHistory::class,
        ];
        
        // Pivot tables to migrate separately
        $pivotTables = [
            'artist_file',
            'album_file', 
            'file_playlist',
            'file_queue',
        ];

        // Disable foreign key constraints for PostgreSQL
        DB::statement('SET session_replication_role = replica');
        
        DB::beginTransaction();
        
        try {
            foreach ($models as $tableName => $modelClass) {
                $this->info("Migrating {$tableName}...");
                
                // Get records from SQLite database
                $records = DB::connection('sqlite-source')->table($tableName)->get();
                $recordCount = $records->count();
                
                if ($recordCount > 0) {
                    // Clear existing data (skip if table doesn't exist or is empty)
                    try {
                        $postgresConnection->table($tableName)->truncate();
                    } catch (\Exception $e) {
                        // Table might not exist or be empty, continue
                    }
                    
                    // Insert in chunks to avoid memory issues
                    $records->chunk(100)->each(function ($chunk) use ($postgresConnection, $tableName) {
                        $data = $chunk->map(function ($record) {
                            $array = (array) $record;
                            // Handle timestamp formatting for PostgreSQL
                            foreach ($array as $key => $value) {
                                if ($value instanceof \DateTime) {
                                    $array[$key] = $value->format('Y-m-d H:i:s');
                                }
                            }
                            return $array;
                        })->toArray();
                        
                        $postgresConnection->table($tableName)->insert($data);
                    });
                }
                
                $this->info("✓ Migrated {$recordCount} {$tableName} records");
            }
            
            // Migrate pivot tables
            foreach ($pivotTables as $tableName) {
                $this->info("Migrating {$tableName}...");
                
                $sqliteData = DB::connection('sqlite-source')->table($tableName)->get();
                $recordCount = $sqliteData->count();
                
                if ($recordCount > 0) {
                    // Clear existing data
                    $postgresConnection->table($tableName)->truncate();
                    
                    // Insert in chunks
                    $sqliteData->chunk(100)->each(function ($chunk) use ($postgresConnection, $tableName) {
                        $data = $chunk->map(function ($record) {
                            $array = (array) $record;
                            // Handle timestamp formatting
                            foreach ($array as $key => $value) {
                                if ($value instanceof \DateTime) {
                                    $array[$key] = $value->format('Y-m-d H:i:s');
                                }
                            }
                            return $array;
                        })->toArray();
                        
                        $postgresConnection->table($tableName)->insert($data);
                    });
                }
                
                $this->info("✓ Migrated {$recordCount} {$tableName} records");
            }
            
            DB::commit();
            
            // Re-enable foreign key constraints
            DB::statement('SET session_replication_role = DEFAULT');
            
            $this->info('Migration completed successfully!');
            
            // Verify migration
            $this->info('\nVerifying migration:');
            foreach ($models as $tableName => $modelClass) {
                $sqliteCount = DB::connection('sqlite-source')->table($tableName)->count();
                $postgresCount = $postgresConnection->table($tableName)->count();
                
                if ($sqliteCount === $postgresCount) {
                    $this->info("✓ {$tableName}: {$postgresCount} records");
                } else {
                    $this->error("✗ {$tableName}: SQLite({$sqliteCount}) != PostgreSQL({$postgresCount})");
                }
            }
            
            // Verify pivot tables
            foreach ($pivotTables as $tableName) {
                $sqliteCount = DB::connection('sqlite-source')->table($tableName)->count();
                $postgresCount = $postgresConnection->table($tableName)->count();
                
                if ($sqliteCount === $postgresCount) {
                    $this->info("✓ {$tableName}: {$postgresCount} records");
                } else {
                    $this->error("✗ {$tableName}: SQLite({$sqliteCount}) != PostgreSQL({$postgresCount})");
                }
            }
            
        } catch (Exception $e) {
            DB::rollback();
            $this->error('Migration failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
