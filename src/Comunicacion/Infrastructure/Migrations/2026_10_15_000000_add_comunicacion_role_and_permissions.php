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
            ['codigo' => 'comunicados.ver', 'nombre' => 'Consultar comunicados y documentos'],
            ['codigo' => 'comunicados.gestionar', 'nombre' => 'Crear y editar borradores de comunicados'],
            ['codigo' => 'comunicados.publicar', 'nombre' => 'Publicar, archivar y reintentar comunicados'],
            ['codigo' => 'comunicados.entregas.ver', 'nombre' => 'Consultar destinatarios y entregas de comunicados'],
        ];
        $codes = array_column($permissions, 'codigo');
        if (DB::table($this->table('roles'))->where('codigo', 'gestor_comunicacion')->exists()
            || DB::table($this->table('permisos'))->whereIn('codigo', $codes)->exists()) {
            throw new RuntimeException('El catálogo de Comunicación ya existe y no pertenece a esta migración.');
        }

        DB::table($this->table('roles'))->insert([
            'codigo' => 'gestor_comunicacion',
            'nombre' => 'Gestor de comunicación',
            'descripcion' => 'Gestiona comunicados, documentos y entregas de notificaciones.',
            'es_sistema' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($permissions as $permission) {
            DB::table($this->table('permisos'))->insert([
                ...$permission,
                'modulo' => 'comunicacion',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            'administrador' => $codes,
            'gestor_comunicacion' => ['edificio.ver', 'estructura.ver', ...$codes],
            'consulta' => ['comunicados.ver'],
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
        if (DB::table($this->table('edificio_usuario_roles'))->where('rol_codigo', 'gestor_comunicacion')->exists()
            || DB::table($this->table('invitaciones_edificio'))->where('rol_codigo', 'gestor_comunicacion')->exists()) {
            throw new RuntimeException('No se puede retirar gestor_comunicacion mientras tenga asignaciones o invitaciones.');
        }
        $codes = ['comunicados.ver', 'comunicados.gestionar', 'comunicados.publicar', 'comunicados.entregas.ver'];
        DB::table($this->table('rol_permisos'))->whereIn('permiso_codigo', $codes)->delete();
        DB::table($this->table('rol_permisos'))->where('rol_codigo', 'gestor_comunicacion')->delete();
        DB::table($this->table('permisos'))->whereIn('codigo', $codes)->delete();
        DB::table($this->table('roles'))->where('codigo', 'gestor_comunicacion')->delete();
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
