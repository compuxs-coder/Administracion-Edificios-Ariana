<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $now = now();
        $permissions = [
            ['codigo' => 'areas_comunes.ver', 'nombre' => 'Consultar espacios comunes y sus reglas'],
            ['codigo' => 'areas_comunes.gestionar', 'nombre' => 'Crear y administrar espacios comunes y reglas'],
            ['codigo' => 'reservas_areas_comunes.ver', 'nombre' => 'Consultar reservas de espacios comunes'],
            ['codigo' => 'reservas_areas_comunes.registrar', 'nombre' => 'Registrar reservas de espacios comunes'],
            ['codigo' => 'reservas_areas_comunes.aprobar', 'nombre' => 'Aprobar o rechazar reservas de espacios comunes'],
            ['codigo' => 'reservas_areas_comunes.cancelar', 'nombre' => 'Cancelar reservas de espacios comunes'],
        ];
        $codes = array_column($permissions, 'codigo');
        if (DB::table($this->table('roles'))->where('codigo', 'gestor_areas_comunes')->exists()
            || DB::table($this->table('permisos'))->whereIn('codigo', $codes)->exists()) {
            throw new RuntimeException('El catálogo de Áreas comunes ya existe y no pertenece a esta migración.');
        }

        DB::table($this->table('roles'))->insert([
            'codigo' => 'gestor_areas_comunes',
            'nombre' => 'Gestor de áreas comunes',
            'descripcion' => 'Gestiona espacios, reglas y reservas de áreas comunes.',
            'es_sistema' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($permissions as $permission) {
            DB::table($this->table('permisos'))->insert([
                ...$permission,
                'modulo' => 'areas_comunes',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            'administrador' => $codes,
            'gestor_areas_comunes' => ['edificio.ver', 'estructura.ver', ...$codes],
            'consulta' => ['areas_comunes.ver', 'reservas_areas_comunes.ver'],
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
        if (DB::table($this->table('edificio_usuario_roles'))->where('rol_codigo', 'gestor_areas_comunes')->exists()
            || DB::table($this->table('invitaciones_edificio'))->where('rol_codigo', 'gestor_areas_comunes')->exists()) {
            throw new RuntimeException('No se puede retirar gestor_areas_comunes mientras tenga asignaciones o invitaciones.');
        }
        $codes = ['areas_comunes.ver', 'areas_comunes.gestionar', 'reservas_areas_comunes.ver', 'reservas_areas_comunes.registrar', 'reservas_areas_comunes.aprobar', 'reservas_areas_comunes.cancelar'];
        DB::table($this->table('rol_permisos'))->whereIn('permiso_codigo', $codes)->delete();
        DB::table($this->table('rol_permisos'))->where('rol_codigo', 'gestor_areas_comunes')->delete();
        DB::table($this->table('permisos'))->whereIn('codigo', $codes)->delete();
        DB::table($this->table('roles'))->where('codigo', 'gestor_areas_comunes')->delete();
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
