<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;

class Playground extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:playground';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $search = File::search('*')
            ->where('mime_group', 'audio')
            ->where('blacklisted', false)
            ->where('not_found', false)
            ->whereIn('love_user_ids', [1])
            ->paginate(250, 2);

        // query files with audio mime type having the reaction 'love'
        $files = \App\Models\File::where('mime_type', 'like', 'audio/%')
            ->whereHas('reactions', function ($query) {
                $query->where('type', 'love');
            })
            // where source not spotify
            ->where('source', '!=', 'spotify')
            ->get();

        dd($files->count(), $search);
    }
}
