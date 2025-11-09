<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LocalDemoUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        User::query()->updateOrCreate(
            ['email' => 'demo.admin@atlas.test'],
            [
                'name' => 'Atlas Demo Admin',
                'password' => Hash::make('demo-admin-password'),
                'is_admin' => true,
            ],
        );
    }
}
