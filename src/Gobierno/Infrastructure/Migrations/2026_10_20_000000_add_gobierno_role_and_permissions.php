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
            ['codigo' => 'gobierno.ver', 'nombre' => 'Consultar el centro de auditoria'],
            ['codigo' => 'gobierno.detalles.ver', 'nombre' => 'Consultar actores y referencias protegidas de auditoria'],
        ];
        $codes = array_column($permissions, 'codigo');
        if (DB::table($this->table('roles'))->where('codigo', 'gestor_gobierno')->exists()
            || DB::table($this->table('permisos'))->whereIn('codigo', $codes)->exists()) {
            throw new RuntimeException('El catalogo de Gobierno ya existe y no pertenece a esta migracion.');
        }

        DB::table($this->table('roles'))->insert([
            'codigo' => 'gestor_gobierno',
            'nombre' => 'Gestor de gobierno',
            'descripcion' => 'Consulta trazabilidad administrativa y datos auditables autorizados.',
            'es_sistema' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($permissions as $permission) {
            DB::table($this->table('permisos'))->insert([
                ...$permission,
                'modulo' => 'gobierno',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $sourceReadPermissions = [
            'edificio.ver',
            'miembros.ver',
            'operaciones.ver',
            'mantenimiento_preventivo.ver',
            'areas_comunes.ver',
            'reservas_areas_comunes.ver',
            'comunicados.ver',
        ];
        foreach ([
            'administrador' => $codes,
            'gestor_gobierno' => [...$sourceReadPermissions, ...$codes],
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
        $codes = ['gobierno.ver', 'gobierno.detalles.ver'];
        if (DB::table($this->table('edificio_usuario_roles'))->where('rol_codigo', 'gestor_gobierno')->exists()
            || DB::table($this->table('invitaciones_edificio'))->where('rol_codigo', 'gestor_gobierno')->exists()
            || $this->roleExistsInAuditHistory()
            || DB::table($this->table('rol_permisos'))->whereIn('permiso_codigo', $codes)
                ->whereNotIn('rol_codigo', ['administrador', 'gestor_gobierno'])->exists()) {
            throw new RuntimeException('No se puede retirar gestor_gobierno ni sus permisos mientras existan asignaciones, invitaciones, historia o concesiones a otros roles.');
        }

        DB::table($this->table('rol_permisos'))->where('rol_codigo', 'gestor_gobierno')->delete();
        DB::table($this->table('rol_permisos'))->whereIn('permiso_codigo', $codes)->delete();
        DB::table($this->table('permisos'))->whereIn('codigo', $codes)->delete();
        DB::table($this->table('roles'))->where('codigo', 'gestor_gobierno')->delete();
    }

    private function roleExistsInAuditHistory(): bool
    {
        return DB::table($this->table('eventos_acceso_edificio'))
            ->where(static fn ($query) => $query
                ->whereRaw('CAST(roles_anteriores AS TEXT) LIKE ?', ['%"gestor_gobierno"%'])
                ->orWhereRaw('CAST(roles_nuevos AS TEXT) LIKE ?', ['%"gestor_gobierno"%']))
            ->exists();
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
