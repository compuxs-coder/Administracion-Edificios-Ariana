<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener categorías para asignar a productos
        $categorias = DB::table('categorias')->pluck('id')->toArray();

        if (empty($categorias)) {
            $this->command->error('No hay categorías. Ejecuta primero CategoriaSeeder.');
            return;
        }

        $productos = [
            ['codigo' => 'PROD001', 'nombre' => 'Laptop Dell XPS 15', 'descripcion' => 'Laptop de alto rendimiento', 'precio' => 3500.00, 'stock' => 10, 'tipo' => 'bien'],
            ['codigo' => 'PROD002', 'nombre' => 'Mouse Logitech MX Master', 'descripcion' => 'Mouse inalámbrico ergonómico', 'precio' => 150.00, 'stock' => 50, 'tipo' => 'bien'],
            ['codigo' => 'PROD003', 'nombre' => 'Teclado Mecánico Keychron K2', 'descripcion' => 'Teclado mecánico inalámbrico', 'precio' => 180.00, 'stock' => 30, 'tipo' => 'bien'],
            ['codigo' => 'PROD004', 'nombre' => 'Monitor LG 27" 4K', 'descripcion' => 'Monitor 4K UHD 27 pulgadas', 'precio' => 450.00, 'stock' => 15, 'tipo' => 'bien'],
            ['codigo' => 'PROD005', 'nombre' => 'Auriculares Sony WH-1000XM5', 'descripcion' => 'Auriculares con cancelación de ruido', 'precio' => 380.00, 'stock' => 25, 'tipo' => 'bien'],
            ['codigo' => 'SERV001', 'nombre' => 'Consultoría IT', 'descripcion' => 'Servicios de consultoría tecnológica', 'precio' => 100.00, 'stock' => 999, 'tipo' => 'servicio'],
            ['codigo' => 'SERV002', 'nombre' => 'Desarrollo Web', 'descripcion' => 'Desarrollo de sitios web personalizados', 'precio' => 150.00, 'stock' => 999, 'tipo' => 'servicio'],
            ['codigo' => 'PROD006', 'nombre' => 'Disco SSD Samsung 1TB', 'descripcion' => 'Disco de estado sólido 1TB', 'precio' => 120.00, 'stock' => 40, 'tipo' => 'bien'],
            ['codigo' => 'PROD007', 'nombre' => 'Webcam Logitech C920', 'descripcion' => 'Cámara web HD 1080p', 'precio' => 90.00, 'stock' => 20, 'tipo' => 'bien'],
            ['codigo' => 'PROD008', 'nombre' => 'Impresora HP LaserJet', 'descripcion' => 'Impresora láser monocromática', 'precio' => 280.00, 'stock' => 12, 'tipo' => 'bien'],
        ];

        foreach ($productos as $producto) {
            DB::table('productos')->insert([
                'id' => DB::raw('gen_random_uuid()'),
                'categoria_id' => $categorias[array_rand($categorias)],
                'codigo' => $producto['codigo'],
                'nombre' => $producto['nombre'],
                'descripcion' => $producto['descripcion'],
                'precio_unitario' => $producto['precio'],
                'stock' => $producto['stock'],
                'tipo' => $producto['tipo'],
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('Productos creados exitosamente.');
    }
}
