<?php

use App\Browser;
use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('attaches files to a tab without creating duplicate pivot rows', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->create(['user_id' => $user->id]);
    $files = File::factory()->count(3)->create();

    $browser = new class extends Browser
    {
        public function attachForTest(int $tabId, array $files): void
        {
            $this->attachFilesToTab($tabId, $files);
        }
    };

    $this->actingAs($user);

    $browser->attachForTest($tab->id, $files->all());
    $browser->attachForTest($tab->id, $files->all());

    $pivotRows = DB::table('tab_file')->where('tab_id', $tab->id)->get();

    expect($pivotRows)->toHaveCount(3);
    expect($pivotRows->pluck('file_id')->unique()->count())->toBe(3);
});
