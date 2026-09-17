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

        DB::table($table('permisos'))->insertOrIgnore([
            'codigo' => 'lecturas.registrar',
            'nombre' => 'Registrar lecturas de consumo',
            'modulo' => 'finanzas',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (['administrador', 'gestor_finanzas'] as $role) {
            DB::table($table('rol_permisos'))->insertOrIgnore([
                'rol_codigo' => $role,
                'permiso_codigo' => 'lecturas.registrar',
            ]);
        }
    }

    public function down(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $table = static fn (string $name): string => $pgsql ? "{$schema}.{$name}" : $name;

        DB::table($table('rol_permisos'))
            ->where('permiso_codigo', 'lecturas.registrar')
            ->delete();
        DB::table($table('permisos'))
            ->where('codigo', 'lecturas.registrar')
            ->delete();
    }
};
