<?php

namespace Database\Seeders;

use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\CategoriaSeeder;
use Database\Seeders\ProductoSeeder;
use Database\Seeders\ClienteSeeder;
use Database\Seeders\ProveedorSeeder;

class DatabaseSeeder extends Seeder

{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CategoriaSeeder::class,
            ProductoSeeder::class,
        ]);
    }
}
