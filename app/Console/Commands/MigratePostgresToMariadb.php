<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Exception;

class MigratePostgresToMariadb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-postgres-to-mariadb';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate data from PostgreSQL to MariaDB';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting migration from PostgreSQL to MariaDB...');

        try {
            // Test MariaDB connection
            $mariadbConnection = DB::connection('mysql');
            $mariadbConnection->getPdo();
            $this->info('✓ MariaDB connection established');
        } catch (Exception $e) {
            $this->error('Failed to connect to MariaDB: ' . $e->getMessage());
            return 1;
        }

        try {
            // Test PostgreSQL source connection
            $postgresConnection = DB::connection('pgsql-source');
            $postgresConnection->getPdo();
            $this->info('✓ PostgreSQL source connection established');
        } catch (Exception $e) {
            $this->error('Failed to connect to PostgreSQL source: ' . $e->getMessage());
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

        // Disable foreign key constraints for MariaDB
        $mariadbConnection->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($models as $tableName => $modelClass) {
                $this->info("Migrating {$tableName}...");
                
                // Get records from PostgreSQL database
                $records = $postgresConnection->table($tableName)->get();
                $recordCount = $records->count();
                
                if ($recordCount > 0) {
                    // Clear existing data (skip if table doesn't exist or is empty)
                    try {
                        $mariadbConnection->table($tableName)->truncate();
                    } catch (\Exception $e) {
                        // Table might not exist or be empty, continue
                    }
                    
                    // Insert in chunks to avoid memory issues
                    $records->chunk(100)->each(function ($chunk) use ($mariadbConnection, $tableName) {
                        $data = $chunk->map(function ($record) {
                            $array = (array) $record;
                            
                            // Handle timestamp formatting for MariaDB
                            foreach ($array as $key => $value) {
                                if ($value instanceof \DateTime) {
                                    $array[$key] = $value->format('Y-m-d H:i:s');
                                }
                                
                                // Handle PostgreSQL boolean values (t/f) to MariaDB (1/0)
                                if ($value === 't' || $value === true) {
                                    $array[$key] = true;
                                } elseif ($value === 'f' || $value === false) {
                                    $array[$key] = false;
                                }
                                
                                // Handle JSON fields - ensure they're properly formatted
                                if (is_string($value) && $this->isJson($value)) {
                                    // Re-encode JSON to ensure consistency
                                    $decoded = json_decode($value, true);
                                    if ($decoded !== null) {
                                        $array[$key] = json_encode($decoded);
                                    }
                                }
                            }
                            
                            return $array;
                        })->toArray();
                        
                        $mariadbConnection->table($tableName)->insert($data);
                    });
                }
                
                $this->info("✓ Migrated {$recordCount} {$tableName} records");
            }
            
            // Migrate pivot tables
            foreach ($pivotTables as $tableName) {
                $this->info("Migrating {$tableName}...");
                
                $postgresData = $postgresConnection->table($tableName)->get();
                $recordCount = $postgresData->count();
                
                if ($recordCount > 0) {
                    // Clear existing data
                    $mariadbConnection->table($tableName)->truncate();
                    
                    // Insert in chunks
                    $postgresData->chunk(100)->each(function ($chunk) use ($mariadbConnection, $tableName) {
                        $data = $chunk->map(function ($record) {
                            $array = (array) $record;
                            
                            // Handle timestamp formatting
                            foreach ($array as $key => $value) {
                                if ($value instanceof \DateTime) {
                                    $array[$key] = $value->format('Y-m-d H:i:s');
                                }
                                
                                // Handle PostgreSQL boolean values
                                if ($value === 't' || $value === true) {
                                    $array[$key] = true;
                                } elseif ($value === 'f' || $value === false) {
                                    $array[$key] = false;
                                }
                            }
                            
                            return $array;
                        })->toArray();
                        
                        $mariadbConnection->table($tableName)->insert($data);
                    });
                }
                
                $this->info("✓ Migrated {$recordCount} {$tableName} records");
            }
            
        // Re-enable foreign key constraints
        $mariadbConnection->statement('SET FOREIGN_KEY_CHECKS=1');
        
        $this->info('Migration completed successfully!');

        // Verify migration (outside transaction)
        $this->info('\nVerifying migration:');
        foreach ($models as $tableName => $modelClass) {
            $postgresCount = $postgresConnection->table($tableName)->count();
            $mariadbCount = $mariadbConnection->table($tableName)->count();
            
            if ($postgresCount === $mariadbCount) {
                $this->info("✓ {$tableName}: {$mariadbCount} records");
            } else {
                $this->error("✗ {$tableName}: PostgreSQL({$postgresCount}) != MariaDB({$mariadbCount})");
            }
        }
        
        // Verify pivot tables
        foreach ($pivotTables as $tableName) {
            $postgresCount = $postgresConnection->table($tableName)->count();
            $mariadbCount = $mariadbConnection->table($tableName)->count();
            
            if ($postgresCount === $mariadbCount) {
                $this->info("✓ {$tableName}: {$mariadbCount} records");
            } else {
                $this->error("✗ {$tableName}: PostgreSQL({$postgresCount}) != MariaDB({$mariadbCount})");
            }
        }

        return 0;
    }

    /**
     * Check if a string is valid JSON
     */
    private function isJson($string): bool
    {
        if (!is_string($string)) {
            return false;
        }
        
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
