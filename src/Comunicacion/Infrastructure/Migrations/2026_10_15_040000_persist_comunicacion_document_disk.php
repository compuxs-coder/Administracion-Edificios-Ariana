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
            throw new RuntimeException('Las protecciones de Comunicación sólo admiten PostgreSQL y SQLite.');
        }
        $table = $this->table('documentos_comunicado');
        $currentDisk = trim((string) config('comunicacion.document_disk'));
        $this->assertPrivateDisk($currentDisk, 'COMUNICACION_DOCUMENT_DISK');
        $existingDocuments = DB::table($table)->count();
        $legacyDisk = trim((string) config('comunicacion.document_legacy_disk'));
        if ($existingDocuments > 0) {
            if ($legacyDisk === '') {
                throw new RuntimeException('Defina COMUNICACION_DOCUMENT_LEGACY_DISK antes de migrar documentos existentes.');
            }
            $this->assertPrivateDisk($legacyDisk, 'COMUNICACION_DOCUMENT_LEGACY_DISK');
        }
        $backfillDisk = $existingDocuments > 0 ? $legacyDisk : $currentDisk;
        Schema::table($table, static function (Blueprint $blueprint) use ($backfillDisk): void {
            $blueprint->string('disco', 100)->default($backfillDisk)->after('comunicado_id');
        });
        if ($driver === 'pgsql') {
            $schema = (string) config('database.application_schema');
            DB::statement("ALTER TABLE \"{$schema}\".\"documentos_comunicado\" ADD CONSTRAINT documentos_comunicado_disco_check CHECK (btrim(disco) <> '')");
        } else {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER documentos_comunicado_disco_insert_guard BEFORE INSERT ON documentos_comunicado
                WHEN trim(NEW.disco) = ''
                BEGIN SELECT RAISE(ABORT, 'El disco privado del documento es obligatorio.'); END;
                SQL);
        }
    }

    public function down(): void
    {
        $this->assertRollbackSafe();
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Las protecciones de Comunicación sólo admiten PostgreSQL y SQLite.');
        }
        $table = $this->table('documentos_comunicado');
        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS documentos_comunicado_disco_insert_guard');
        } else {
            $schema = (string) config('database.application_schema');
            DB::statement("ALTER TABLE \"{$schema}\".\"documentos_comunicado\" DROP CONSTRAINT documentos_comunicado_disco_check");
        }
        Schema::table($table, static function (Blueprint $blueprint): void {
            $blueprint->dropColumn('disco');
        });
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }

    private function assertPrivateDisk(string $disk, string $variable): void
    {
        $diskConfig = config("filesystems.disks.{$disk}");
        if ($disk === '' || mb_strlen($disk) > 100 || ! is_array($diskConfig)
            || ($diskConfig['visibility'] ?? null) !== 'private' || ($diskConfig['serve'] ?? false) === true) {
            throw new RuntimeException("{$variable} debe identificar un disco privado no servido de hasta 100 caracteres.");
        }
    }

    private function assertRollbackSafe(): void
    {
        if (DB::table($this->table('edificio_usuario_roles'))->where('rol_codigo', 'gestor_comunicacion')->exists()
            || DB::table($this->table('invitaciones_edificio'))->where('rol_codigo', 'gestor_comunicacion')->exists()) {
            throw new RuntimeException('No se puede revertir Comunicación mientras gestor_comunicacion tenga asignaciones o invitaciones.');
        }
        foreach (['bitacora_comunicacion', 'destinatarios_comunicado', 'entregas_comunicado', 'documentos_comunicado', 'comunicados'] as $name) {
            if (DB::table($this->table($name))->exists()) {
                throw new RuntimeException('No se puede revertir Comunicación mientras exista historial.');
            }
        }
    }
};
