<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $table = fn (string $name): string => $this->table($name);
        $now = now();
        $permissions = [
            ['codigo' => 'operaciones.ver', 'nombre' => 'Consultar órdenes operativas'],
            ['codigo' => 'operaciones.gestionar', 'nombre' => 'Crear y actualizar órdenes operativas'],
            ['codigo' => 'operaciones.cambiar_estado', 'nombre' => 'Avanzar órdenes operativas'],
            ['codigo' => 'operaciones.asignar', 'nombre' => 'Asignar responsables operativos'],
            ['codigo' => 'operaciones.cancelar', 'nombre' => 'Cancelar órdenes operativas'],
            ['codigo' => 'operaciones.reabrir', 'nombre' => 'Reabrir órdenes operativas'],
        ];
        $codes = array_column($permissions, 'codigo');
        if (DB::table($table('roles'))->where('codigo', 'gestor_operaciones')->exists()
            || DB::table($table('permisos'))->whereIn('codigo', $codes)->exists()) {
            throw new \RuntimeException('El catálogo de Operaciones ya existe y no pertenece a esta migración.');
        }

        DB::table($table('roles'))->insert([
            'codigo' => 'gestor_operaciones',
            'nombre' => 'Gestor de operaciones',
            'descripcion' => 'Gestiona incidencias, solicitudes y mantenimiento correctivo.',
            'es_sistema' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($permissions as $permission) {
            DB::table($table('permisos'))->insert([
                ...$permission,
                'modulo' => 'operaciones',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $matrix = [
            'administrador' => $codes,
            'gestor_operaciones' => ['edificio.ver', 'estructura.ver', ...$codes],
            'consulta' => ['operaciones.ver'],
        ];
        foreach ($matrix as $role => $codes) {
            foreach ($codes as $code) {
                DB::table($table('rol_permisos'))->insert([
                    'rol_codigo' => $role,
                    'permiso_codigo' => $code,
                ]);
            }
        }
    }

    public function down(): void
    {
        $table = fn (string $name): string => $this->table($name);
        if (DB::table($table('edificio_usuario_roles'))->where('rol_codigo', 'gestor_operaciones')->exists()) {
            throw new \RuntimeException('No se puede retirar el rol gestor_operaciones mientras tenga asignaciones.');
        }
        if (DB::table($table('invitaciones_edificio'))->where('rol_codigo', 'gestor_operaciones')->exists()) {
            throw new \RuntimeException('No se puede retirar el rol gestor_operaciones mientras tenga invitaciones.');
        }

        $codes = [
            'operaciones.ver',
            'operaciones.gestionar',
            'operaciones.cambiar_estado',
            'operaciones.asignar',
            'operaciones.cancelar',
            'operaciones.reabrir',
        ];
        DB::table($table('rol_permisos'))->whereIn('permiso_codigo', $codes)->delete();
        DB::table($table('rol_permisos'))->where('rol_codigo', 'gestor_operaciones')->delete();
        DB::table($table('permisos'))->whereIn('codigo', $codes)->delete();
        DB::table($table('roles'))->where('codigo', 'gestor_operaciones')->delete();
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
