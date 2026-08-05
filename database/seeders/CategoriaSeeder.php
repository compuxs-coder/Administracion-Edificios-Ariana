<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoriaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categorias = [
            ['nombre' => 'Electrónica', 'descripcion' => 'Productos electrónicos'],
            ['nombre' => 'Alimentos', 'descripcion' => 'Productos alimenticios'],
            ['nombre' => 'Ropa', 'descripcion' => 'Prendas de vestir'],
            ['nombre' => 'Servicios', 'descripcion' => 'Servicios profesionales'],
            ['nombre' => 'Otros', 'descripcion' => 'Otros productos'],
        ];

        foreach ($categorias as $categoria) {
            DB::table('categorias')->insert([
                'id' => DB::raw('gen_random_uuid()'),
                'nombre' => $categoria['nombre'],
                'descripcion' => $categoria['descripcion'],
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
