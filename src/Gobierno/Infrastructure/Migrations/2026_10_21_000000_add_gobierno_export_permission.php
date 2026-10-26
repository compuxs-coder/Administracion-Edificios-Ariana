<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $permissions = $this->table('permisos');
        $rolePermissions = $this->table('rol_permisos');
        if (DB::table($permissions)->where('codigo', 'gobierno.exportar')->exists()) {
            throw new RuntimeException('El permiso gobierno.exportar ya existe y no pertenece a esta migracion.');
        }
        $now = now();
        DB::table($permissions)->insert([
            'codigo' => 'gobierno.exportar',
            'nombre' => 'Generar y descargar exportaciones de auditoria',
            'modulo' => 'gobierno',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach (['administrador', 'gestor_gobierno'] as $role) {
            DB::table($rolePermissions)->insert([
                'rol_codigo' => $role,
                'permiso_codigo' => 'gobierno.exportar',
            ]);
        }
    }

    public function down(): void
    {
        $rolePermissions = $this->table('rol_permisos');
        if (DB::table($rolePermissions)->where('permiso_codigo', 'gobierno.exportar')
            ->whereNotIn('rol_codigo', ['administrador', 'gestor_gobierno'])->exists()) {
            throw new RuntimeException('No se puede retirar gobierno.exportar mientras este concedido a otros roles.');
        }
        if ($this->tableExists('exportaciones_auditoria')
            && DB::table($this->table('exportaciones_auditoria'))->exists()) {
            throw new RuntimeException('No se puede retirar gobierno.exportar mientras exista historial de exportaciones.');
        }
        DB::table($rolePermissions)->where('permiso_codigo', 'gobierno.exportar')->delete();
        DB::table($this->table('permisos'))->where('codigo', 'gobierno.exportar')->delete();
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }

    private function tableExists(string $name): bool
    {
        return Illuminate\Support\Facades\Schema::hasTable($this->table($name));
    }
};
