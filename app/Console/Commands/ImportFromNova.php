<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportFromNova extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-from-nova';

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
        $oldFiles = DB::connection('sqlite-dev')
            ->table('files');

        foreach($oldFiles->get() as $oldFile){
            $this->warn("Importing file: {$oldFile->path} ({$oldFile->id})");
//            {#609
//                +"id": 1
//            +"path": "F:\0000 - Downloads\(2011) Time Of My Life\(2011) Time Of My Life.jpg"
//            +"name": "(2011) Time Of My Life"
//            +"extension": ".jpg"
//            +"type": "image/jpeg"
//            +"size": 45193
//            +"rating": 0
//            +"highQualityPath": null
//            +"lowQualityPath": null
//            +"signature": "b55443f29fb6fe3478d50af12be1811c"
//            +"createdAt": "2022-06-16 07:03:10.209 +00:00"
//            +"updatedAt": "2024-04-23 00:36:31.245 +00:00"
//} // app\Consol

            $file = File::firstOrCreate([
                'path' => $oldFile->path,
            ], [
                'source' => 'Nova',
                'filename' => $oldFile->name,
                'ext' => $oldFile->extension,
                'size' => $oldFile->size,
                'mime_type' => $oldFile->type,
                'hash' => $oldFile->signature,
                'title' => $oldFile->name,
                'liked' => $oldFile->rating > 1 && $oldFile->rating < 5,
                'liked_at' => $oldFile->rating > 1 && $oldFile->rating < 5 ? now() : null,
                'disliked' => $oldFile->rating < 2,
                'disliked_at' => $oldFile->rating < 2 ? now() : null,
                'loved' => $oldFile->rating >= 5,
                'loved_at' => $oldFile->rating >= 5 ? now() : null,
            ]);

            $this->info("Imported file: {$file->path} ({$file->id})");
        }
    }
}
