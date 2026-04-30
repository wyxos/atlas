<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DropLegacyBlacklistReasonColumn extends Command
{
    protected $signature = 'atlas:drop-legacy-blacklist-reason-column
        {--dry-run : Show the online DDL without executing it}
        {--force : Required to execute the column drop}';

    protected $description = 'Drop files.blacklist_reason using online DDL after legacy conversion';

    public function handle(): int
    {
        if (! Schema::hasColumn('files', 'blacklist_reason')) {
            $this->info('files.blacklist_reason is already absent.');

            return self::SUCCESS;
        }

        $sql = 'ALTER TABLE files DROP COLUMN blacklist_reason, ALGORITHM=INPLACE, LOCK=NONE';

        if ((bool) $this->option('dry-run')) {
            $this->line($sql);

            return self::SUCCESS;
        }

        if (! (bool) $this->option('force')) {
            $this->error('Pass --force to execute the online column drop.');

            return self::FAILURE;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('Online DDL column drop is only supported here for MySQL/MariaDB.');

            return self::FAILURE;
        }

        DB::statement($sql);
        $this->info('Dropped files.blacklist_reason.');

        return self::SUCCESS;
    }
}
