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
            ['codigo' => 'tesoreria.ver', 'nombre' => 'Consultar tesorería'],
            ['codigo' => 'cuentas_tesoreria.gestionar', 'nombre' => 'Gestionar cuentas de tesorería'],
            ['codigo' => 'movimientos_tesoreria.registrar', 'nombre' => 'Registrar movimientos de tesorería'],
            ['codigo' => 'movimientos_tesoreria.anular', 'nombre' => 'Anular movimientos de tesorería'],
            ['codigo' => 'conciliaciones.gestionar', 'nombre' => 'Gestionar conciliaciones de tesorería'],
        ];
        foreach ($permissions as $permission) {
            DB::table($table('permisos'))->insertOrIgnore([
                ...$permission,
                'modulo' => 'tesoreria',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $all = array_column($permissions, 'codigo');
        $matrix = [
            'administrador' => $all,
            'gestor_finanzas' => $all,
            'consulta' => ['tesoreria.ver'],
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
        $codes = [
            'tesoreria.ver',
            'cuentas_tesoreria.gestionar',
            'movimientos_tesoreria.registrar',
            'movimientos_tesoreria.anular',
            'conciliaciones.gestionar',
        ];

        DB::table($table('rol_permisos'))->whereIn('permiso_codigo', $codes)->delete();
        DB::table($table('permisos'))->whereIn('codigo', $codes)->delete();
    }
};
