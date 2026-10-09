<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Safe to run more than once.
     */
    public function run(): void
    {
        $this->call([
            AiCatalogSeeder::class,
            ToolCatalogSeeder::class,
        ]);

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->make(['name' => 'Test User'])->only(['name', 'password']),
        );
    }
}
