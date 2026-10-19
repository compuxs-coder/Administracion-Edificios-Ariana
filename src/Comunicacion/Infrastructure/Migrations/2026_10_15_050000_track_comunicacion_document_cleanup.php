<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('La limpieza de documentos de Comunicación sólo admite PostgreSQL y SQLite.');
        }
        $table = $this->table('limpieza_documentos_comunicacion');
        Schema::create($table, static function (Blueprint $blueprint): void {
            $blueprint->uuid('id')->primary();
            $blueprint->string('disco', 100);
            $blueprint->string('ruta_privada', 500);
            $blueprint->timestampTz('created_at', 6)->useCurrent();
            $blueprint->unique(['disco', 'ruta_privada'], 'limpieza_documentos_comunicacion_ruta_uq');
            $blueprint->index('created_at', 'limpieza_documentos_comunicacion_fecha_idx');
        });
        if ($driver === 'pgsql') {
            $schema = (string) config('database.application_schema');
            DB::statement("ALTER TABLE \"{$schema}\".\"limpieza_documentos_comunicacion\" ADD CONSTRAINT limpieza_documentos_comunicacion_forma_check CHECK (btrim(disco) <> '' AND btrim(ruta_privada) <> '')");
        } else {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER limpieza_documentos_comunicacion_insert_guard BEFORE INSERT ON limpieza_documentos_comunicacion
                WHEN trim(NEW.disco) = '' OR trim(NEW.ruta_privada) = ''
                BEGIN SELECT RAISE(ABORT, 'El registro de limpieza de Comunicación no tiene una forma válida.'); END;
                SQL);
        }
    }

    public function down(): void
    {
        $this->assertRollbackSafe();
        $table = $this->table('limpieza_documentos_comunicacion');
        Schema::dropIfExists($table);
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }

    private function assertRollbackSafe(): void
    {
        if (DB::table($this->table('edificio_usuario_roles'))->where('rol_codigo', 'gestor_comunicacion')->exists()
            || DB::table($this->table('invitaciones_edificio'))->where('rol_codigo', 'gestor_comunicacion')->exists()) {
            throw new RuntimeException('No se puede revertir Comunicación mientras gestor_comunicacion tenga asignaciones o invitaciones.');
        }
        foreach (['limpieza_documentos_comunicacion', 'bitacora_comunicacion', 'destinatarios_comunicado', 'entregas_comunicado', 'documentos_comunicado', 'comunicados'] as $name) {
            if (DB::table($this->table($name))->exists()) {
                throw new RuntimeException('No se puede revertir Comunicación mientras exista historial.');
            }
        }
    }
};
