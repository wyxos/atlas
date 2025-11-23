<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Demo user
        User::create([
            'name' => 'Demo User',
            'email' => 'demo@atlas.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }
}
