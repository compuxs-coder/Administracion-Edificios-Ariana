<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ComunicacionPostgresMigrationTest extends TestCase
{
    private string $originalSchema;

    protected function setUp(): void
    {
        parent::setUp();
        if (env('COMUNICACION_PG_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requiere COMUNICACION_PG_INTEGRATION=1 y una base PostgreSQL local desechable o de desarrollo.');
        }
        $connection = (array) config('database.connections.pgsql');
        if (config('database.default') !== 'pgsql'
            || ! in_array((string) ($connection['host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true)
            || (bool) DB::scalar('SELECT pg_is_in_recovery()')) {
            throw new RuntimeException('La integración de Comunicación sólo admite PostgreSQL local primario.');
        }
        $this->originalSchema = (string) config('database.application_schema');
    }

    public function test_document_disk_backfill_does_not_fire_immutable_update_trigger(): void
    {
        $schema = $this->temporarySchema();
        DB::beginTransaction();
        try {
            DB::statement("CREATE SCHEMA \"{$schema}\"");
            DB::statement("CREATE TABLE \"{$schema}\".documentos_comunicado (id uuid PRIMARY KEY)");
            DB::statement("INSERT INTO \"{$schema}\".documentos_comunicado (id) VALUES ('00000000-0000-4000-8000-000000000001')");
            DB::unprepared(<<<SQL
                CREATE FUNCTION "{$schema}".reject_document_update()
                RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    RAISE EXCEPTION 'Los documentos son inmutables.' USING ERRCODE = '23514';
                END;
                \$\$;
                CREATE TRIGGER documentos_comunicado_update_guard
                BEFORE UPDATE ON "{$schema}".documentos_comunicado
                FOR EACH ROW EXECUTE FUNCTION "{$schema}".reject_document_update();
                SQL);
            config([
                'database.application_schema' => $schema,
                'comunicacion.document_disk' => 'communications',
                'comunicacion.document_legacy_disk' => 'communications',
            ]);

            $migration = require base_path('src/Comunicacion/Infrastructure/Migrations/2026_10_15_040000_persist_comunicacion_document_disk.php');
            $migration->up();

            $this->assertSame('communications', DB::table($schema.'.documentos_comunicado')->value('disco'));
            $this->assertSame(1, (int) DB::scalar(<<<'SQL'
                SELECT count(*) FROM pg_trigger trigger_row
                JOIN pg_class table_row ON table_row.oid = trigger_row.tgrelid
                JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
                WHERE schema_row.nspname = ? AND table_row.relname = 'documentos_comunicado'
                  AND trigger_row.tgname = 'documentos_comunicado_update_guard'
                  AND NOT trigger_row.tgisinternal
                SQL, [$schema]));
        } finally {
            config(['database.application_schema' => $this->originalSchema]);
            DB::rollBack();
        }
    }

    public function test_each_late_migration_refuses_rollback_before_schema_changes(): void
    {
        $schema = $this->temporarySchema();
        DB::beginTransaction();
        try {
            DB::statement("CREATE SCHEMA \"{$schema}\"");
            foreach (['edificio_usuario_roles', 'invitaciones_edificio'] as $table) {
                DB::statement("CREATE TABLE \"{$schema}\".\"{$table}\" (rol_codigo text)");
            }
            foreach (['bitacora_comunicacion', 'destinatarios_comunicado', 'entregas_comunicado', 'comunicados'] as $table) {
                DB::statement("CREATE TABLE \"{$schema}\".\"{$table}\" (id integer)");
            }
            DB::statement("CREATE TABLE \"{$schema}\".documentos_comunicado (id integer, disco text)");
            DB::statement("CREATE TABLE \"{$schema}\".limpieza_documentos_comunicacion (id integer)");
            config(['database.application_schema' => $schema]);

            DB::table($schema.'.limpieza_documentos_comunicacion')->insert(['id' => 1]);
            $this->assertRollbackRejected('050000_track_comunicacion_document_cleanup', 'historial');
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($schema.'.limpieza_documentos_comunicacion'));
            DB::table($schema.'.limpieza_documentos_comunicacion')->delete();

            DB::table($schema.'.documentos_comunicado')->insert(['id' => 1, 'disco' => 'communications']);
            $this->assertRollbackRejected('040000_persist_comunicacion_document_disk', 'historial');
            $this->assertTrue(DB::getSchemaBuilder()->hasColumn($schema.'.documentos_comunicado', 'disco'));
            DB::table($schema.'.documentos_comunicado')->delete();

            DB::table($schema.'.invitaciones_edificio')->insert(['rol_codigo' => 'gestor_comunicacion']);
            $this->assertRollbackRejected('030000_require_non_empty_comunicacion_publication', 'gestor_comunicacion');
            DB::table($schema.'.invitaciones_edificio')->delete();

            DB::table($schema.'.comunicados')->insert(['id' => 1]);
            $this->assertRollbackRejected('020000_harden_comunicacion_integrity', 'historial');
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($schema.'.comunicados'));
        } finally {
            config(['database.application_schema' => $this->originalSchema]);
            DB::rollBack();
        }
    }

    private function assertRollbackRejected(string $migrationName, string $message): void
    {
        $migration = require base_path("src/Comunicacion/Infrastructure/Migrations/2026_10_15_{$migrationName}.php");
        try {
            $migration->down();
            $this->fail("La migración {$migrationName} modificó el esquema antes del preflight.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function temporarySchema(): string
    {
        return 'stage19_it_'.bin2hex(random_bytes(8));
    }
}
