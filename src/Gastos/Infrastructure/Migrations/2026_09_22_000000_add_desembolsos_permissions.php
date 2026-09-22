<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;
        $now = now();
        $permissions = [
            ['codigo' => 'desembolsos.ver', 'nombre' => 'Consultar desembolsos a proveedores'],
            ['codigo' => 'desembolsos.registrar', 'nombre' => 'Registrar desembolsos a proveedores'],
            ['codigo' => 'desembolsos.anular', 'nombre' => 'Anular desembolsos a proveedores'],
        ];
        foreach ($permissions as $permission) {
            DB::table($table('permisos'))->insertOrIgnore([
                ...$permission,
                'modulo' => 'gastos',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $matrix = [
            'administrador' => ['desembolsos.ver', 'desembolsos.registrar', 'desembolsos.anular'],
            'gestor_finanzas' => ['desembolsos.ver', 'desembolsos.registrar', 'desembolsos.anular'],
            'consulta' => ['desembolsos.ver'],
        ];
        foreach ($matrix as $role => $codes) {
            foreach ($codes as $code) {
                DB::table($table('rol_permisos'))->insertOrIgnore([
                    'rol_codigo' => $role,
                    'permiso_codigo' => $code,
                ]);
            }
        }
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;
        $codes = ['desembolsos.ver', 'desembolsos.registrar', 'desembolsos.anular'];

        DB::table($table('rol_permisos'))->whereIn('permiso_codigo', $codes)->delete();
        DB::table($table('permisos'))->whereIn('codigo', $codes)->delete();
    }
};
