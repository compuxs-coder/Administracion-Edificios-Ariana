<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $permissions = [
            ['codigo' => 'mantenimiento_preventivo.ver', 'nombre' => 'Consultar planes de mantenimiento preventivo'],
            ['codigo' => 'mantenimiento_preventivo.gestionar', 'nombre' => 'Crear y actualizar planes de mantenimiento preventivo'],
            ['codigo' => 'mantenimiento_preventivo.programar', 'nombre' => 'Programar planes y gestionar ocurrencias preventivas'],
        ];
        $codes = array_column($permissions, 'codigo');
        if (DB::table($this->table('permisos'))->whereIn('codigo', $codes)->exists()) {
            throw new RuntimeException('El catálogo de mantenimiento preventivo ya existe y no pertenece a esta migración.');
        }

        $now = now();
        foreach ($permissions as $permission) {
            DB::table($this->table('permisos'))->insert([
                ...$permission,
                'modulo' => 'operaciones',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            'administrador' => $codes,
            'gestor_operaciones' => $codes,
            'consulta' => ['mantenimiento_preventivo.ver'],
        ] as $role => $rolePermissions) {
            foreach ($rolePermissions as $permission) {
                DB::table($this->table('rol_permisos'))->insert([
                    'rol_codigo' => $role,
                    'permiso_codigo' => $permission,
                ]);
            }
        }
    }

    public function down(): void
    {
        $codes = ['mantenimiento_preventivo.ver', 'mantenimiento_preventivo.gestionar', 'mantenimiento_preventivo.programar'];
        DB::table($this->table('rol_permisos'))->whereIn('permiso_codigo', $codes)->delete();
        DB::table($this->table('permisos'))->whereIn('codigo', $codes)->delete();
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
