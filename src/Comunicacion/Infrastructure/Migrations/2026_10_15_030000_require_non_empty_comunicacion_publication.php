<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $this->replaceSqliteShapeGuard(true);

            return;
        }
        if ($driver !== 'pgsql') {
            throw new RuntimeException('Las protecciones de Comunicación sólo admiten PostgreSQL y SQLite.');
        }
        $schema = (string) config('database.application_schema');
        if (DB::table($schema.'.comunicados')->whereIn('estado', ['publicado', 'archivado'])
            ->where(static fn ($query) => $query->where('total_candidatos', '<', 1)->orWhere('total_entregas', '<', 1))->exists()) {
            throw new RuntimeException('Existen comunicados publicados sin audiencia entregable; corrija los datos antes de continuar.');
        }
        DB::unprepared(<<<SQL
            ALTER TABLE "{$schema}"."comunicados" DROP CONSTRAINT comunicados_estado_fechas_check;
            ALTER TABLE "{$schema}"."comunicados" ADD CONSTRAINT comunicados_estado_fechas_check CHECK (
                (estado = 'borrador' AND publicado_por_user_id IS NULL AND publicado_at IS NULL AND archivado_por_user_id IS NULL AND archivado_at IS NULL AND total_candidatos = 0 AND total_entregas = 0 AND total_omitidos = 0)
                OR (estado = 'publicado' AND publicado_por_user_id IS NOT NULL AND publicado_at IS NOT NULL AND archivado_por_user_id IS NULL AND archivado_at IS NULL AND total_candidatos > 0 AND total_entregas > 0)
                OR (estado = 'archivado' AND publicado_por_user_id IS NOT NULL AND publicado_at IS NOT NULL AND archivado_por_user_id IS NOT NULL AND archivado_at IS NOT NULL AND archivado_at >= publicado_at AND total_candidatos > 0 AND total_entregas > 0)
            );
            SQL);
    }

    public function down(): void
    {
        $this->assertRollbackSafe();
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $this->replaceSqliteShapeGuard(false);

            return;
        }
        if ($driver !== 'pgsql') {
            throw new RuntimeException('Las protecciones de Comunicación sólo admiten PostgreSQL y SQLite.');
        }
        $schema = (string) config('database.application_schema');
        DB::unprepared(<<<SQL
            ALTER TABLE "{$schema}"."comunicados" DROP CONSTRAINT comunicados_estado_fechas_check;
            ALTER TABLE "{$schema}"."comunicados" ADD CONSTRAINT comunicados_estado_fechas_check CHECK (
                (estado = 'borrador' AND publicado_por_user_id IS NULL AND publicado_at IS NULL AND archivado_por_user_id IS NULL AND archivado_at IS NULL AND total_candidatos = 0 AND total_entregas = 0 AND total_omitidos = 0)
                OR (estado = 'publicado' AND publicado_por_user_id IS NOT NULL AND publicado_at IS NOT NULL AND archivado_por_user_id IS NULL AND archivado_at IS NULL)
                OR (estado = 'archivado' AND publicado_por_user_id IS NOT NULL AND publicado_at IS NOT NULL AND archivado_por_user_id IS NOT NULL AND archivado_at IS NOT NULL AND archivado_at >= publicado_at)
            );
            SQL);
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

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }

    private function replaceSqliteShapeGuard(bool $requireNonEmpty): void
    {
        $nonEmpty = $requireNonEmpty
            ? "\n              OR (NEW.estado IN ('publicado', 'archivado') AND (NEW.total_candidatos < 1 OR NEW.total_entregas < 1))"
            : '';
        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS comunicados_shape_update_guard;
            CREATE TRIGGER comunicados_shape_update_guard BEFORE UPDATE ON comunicados
            WHEN trim(NEW.titulo) = '' OR trim(NEW.cuerpo) = '' OR (NEW.audiencia_propietarios = 0 AND NEW.audiencia_residentes = 0)
              OR NEW.total_candidatos < NEW.total_omitidos OR NEW.total_entregas > NEW.total_candidatos - NEW.total_omitidos
              OR (NEW.estado = 'borrador' AND (NEW.publicado_por_user_id IS NOT NULL OR NEW.publicado_at IS NOT NULL OR NEW.archivado_por_user_id IS NOT NULL OR NEW.archivado_at IS NOT NULL OR NEW.total_candidatos <> 0 OR NEW.total_entregas <> 0 OR NEW.total_omitidos <> 0))
              OR (NEW.estado = 'publicado' AND (NEW.publicado_por_user_id IS NULL OR NEW.publicado_at IS NULL OR NEW.archivado_por_user_id IS NOT NULL OR NEW.archivado_at IS NOT NULL))
              OR (NEW.estado = 'archivado' AND (NEW.publicado_por_user_id IS NULL OR NEW.publicado_at IS NULL OR NEW.archivado_por_user_id IS NULL OR NEW.archivado_at IS NULL OR NEW.archivado_at < NEW.publicado_at)){$nonEmpty}
              OR (NEW.estado IN ('publicado', 'archivado') AND ((SELECT COUNT(*) FROM destinatarios_comunicado WHERE comunicado_id = NEW.id) <> NEW.total_candidatos OR (SELECT COUNT(*) FROM entregas_comunicado WHERE comunicado_id = NEW.id) <> NEW.total_entregas OR (SELECT COUNT(*) FROM destinatarios_comunicado WHERE comunicado_id = NEW.id AND estado = 'omitido') <> NEW.total_omitidos))
            BEGIN SELECT RAISE(ABORT, 'El comunicado tiene una forma inválida.'); END;
            SQL);
    }
};
