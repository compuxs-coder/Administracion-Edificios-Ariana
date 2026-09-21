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
            ['codigo' => 'gastos.ver', 'nombre' => 'Consultar gastos y cuentas por pagar'],
            ['codigo' => 'gastos.gestionar', 'nombre' => 'Gestionar proveedores, contratos y gastos'],
            ['codigo' => 'gastos.anular', 'nombre' => 'Anular contratos y gastos'],
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
            'administrador' => ['gastos.ver', 'gastos.gestionar', 'gastos.anular'],
            'gestor_finanzas' => ['gastos.ver', 'gastos.gestionar', 'gastos.anular'],
            'consulta' => ['gastos.ver'],
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
        $codes = ['gastos.ver', 'gastos.gestionar', 'gastos.anular'];

        DB::table($table('rol_permisos'))->whereIn('permiso_codigo', $codes)->delete();
        DB::table($table('permisos'))->whereIn('codigo', $codes)->delete();
    }
};
