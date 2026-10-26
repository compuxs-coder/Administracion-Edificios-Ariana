<?php

namespace Tests\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Gobierno\Domain\Contracts\AuditoriaRepositoryInterface;
use Src\Gobierno\Application\Actions\GenerarExportacionAuditoriaAction;
use Src\Gobierno\Application\Actions\SolicitarExportacionAuditoriaAction;
use Src\Gobierno\Infrastructure\Models\ExportacionAuditoriaEloquentModel;
use Tests\TestCase;

final class GobiernoPostgresReadModelTest extends TestCase
{
    public function test_unified_native_read_model_runs_on_local_postgresql_without_persisting_fixture_data(): void
    {
        $this->requireLocalPostgres();

        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->withoutMiddleware(ValidateCsrfToken::class);
            if (! DB::table($this->table('permisos'))->where('codigo', 'gobierno.ver')->exists()) {
                $migration = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_20_000000_add_gobierno_role_and_permissions.php');
                $migration->up();
            }
            if (! DB::table($this->table('permisos'))->where('codigo', 'gobierno.exportar')->exists()) {
                $migration = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_000000_add_gobierno_export_permission.php');
                $migration->up();
            }
            if (! Schema::hasTable($this->table('exportaciones_auditoria'))) {
                $migration = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_010000_create_gobierno_export_tables.php');
                $migration->up();
                $hardening = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_020000_harden_gobierno_export_integrity.php');
                $hardening->up();
            }
            $administrator = UserEloquentModel::factory()->create();
            DB::beginTransaction();
            try {
                DB::table($this->table('exportaciones_auditoria'))->insert([
                    'id' => (string) Str::uuid(),
                    'solicitante_user_id' => $administrator->id,
                    'idempotency_key' => (string) Str::uuid(),
                    'formato' => 'csv',
                    'estado' => 'pendiente',
                    'desde_utc' => '2026-10-20 00:00:00+00',
                    'hasta_utc' => '2026-10-21 00:00:00+00',
                    'corte_at' => '2026-10-20 00:00:00+00',
                    'disponible_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::rollBack();
                $this->fail('PostgreSQL aceptó un corte exclusivo igual al inicio.');
            } catch (QueryException $exception) {
                DB::rollBack();
                $this->assertSame('23514', $exception->errorInfo[0] ?? null);
            }
            $suffix = (string) random_int(1000000000, 1999999999);
            $building = app(CreateEdificioAction::class)->execute([
                'nombre' => 'Integracion Gobierno '.$suffix,
                'ruc' => str_pad($suffix, 13, '0', STR_PAD_LEFT),
                'direccion' => 'Fixture transaccional',
                'ciudad' => 'Quito',
                'timezone' => 'America/Guayaquil',
                'telefono' => null,
                'correo' => null,
                'responsable' => null,
            ], $administrator->id);
            $this->createNativeEvents($administrator, $building->id());
            foreach ([
                'bitacora_orden_operativa',
                'bitacora_plan_mantenimiento',
                'bitacora_areas_comunes',
                'bitacora_comunicacion',
            ] as $auditTable) {
                $this->assertSame(1, DB::table($this->table($auditTable))->where('edificio_id', $building->id())->count(), $auditTable);
            }
            CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->startOfSecond()->addSeconds(2));

            $page = app(AuditoriaRepositoryInterface::class)->paginateForUser($administrator->id, [
                'edificios' => [$building->id()],
            ]);

            $this->assertSame('pgsql', DB::connection()->getDriverName());
            $this->assertSame(5, $page['total']);
            $this->assertEqualsCanonicalizing(
                ['accesos', 'operaciones', 'mantenimiento', 'areas_comunes', 'comunicacion'],
                array_column($page['items'], 'fuente'),
            );
            $this->assertSame([$building->id()], array_values(array_unique(array_column($page['items'], 'edificioId'))));
            $this->assertSame(12, strlen($page['items'][0]['id']));
            foreach ($page['items'] as $event) {
                $this->assertStringEndsWith('Z', $event['ocurridoAt']);
            }
            $this->assertNotSame(
                DB::table($this->table('eventos_acceso_edificio'))->where('edificio_id', $building->id())->value('id'),
                collect($page['items'])->firstWhere('fuente', 'accesos')['id'],
            );

            $chunk = app(AuditoriaRepositoryInterface::class)->exportChunkForUser($administrator->id, [
                'edificios' => [$building->id()],
                'fuente' => null,
                'tipo' => null,
                'desde_utc' => now()->subDay()->utc()->toIso8601ZuluString(),
                'hasta_utc' => now()->addMinute()->utc()->toIso8601ZuluString(),
                'corte_at' => now()->utc()->toIso8601ZuluString(),
            ], null, 2);
            $this->assertCount(2, $chunk['items']);
            $this->assertTrue($chunk['hasMore']);
            $this->assertNotNull($chunk['nextCursor']);
            $allExported = $chunk['items'];
            while ($chunk['hasMore']) {
                $chunk = app(AuditoriaRepositoryInterface::class)->exportChunkForUser($administrator->id, [
                    'edificios' => [$building->id()],
                    'fuente' => null,
                    'tipo' => null,
                    'desde_utc' => now()->subDay()->utc()->toIso8601ZuluString(),
                    'hasta_utc' => now()->addMinute()->utc()->toIso8601ZuluString(),
                    'corte_at' => now()->utc()->toIso8601ZuluString(),
                ], $chunk['nextCursor'], 2);
                $allExported = [...$allExported, ...$chunk['items']];
            }
            $this->assertCount(5, $allExported);
            $this->assertCount(5, array_unique(array_column($allExported, 'id')));

            Queue::fake();
            Storage::fake('audit-exports');
            $request = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, [
                'idempotency_key' => (string) Str::uuid(),
                'formato' => 'csv',
                'edificios' => [$building->id()],
                'fuente' => null,
                'tipo' => null,
                'desde_utc' => now()->subDay()->utc()->toIso8601ZuluString(),
                'hasta_utc' => now()->addMinute()->utc()->toIso8601ZuluString(),
            ]);
            $probeName = 'gobierno_permission_fence_probe';
            config(["database.connections.{$probeName}" => config('database.connections.pgsql')]);
            DB::purge($probeName);
            $probe = DB::connection($probeName);
            $probe->statement("SET lock_timeout = '150ms'");
            $probeAttempted = false;
            $permissionFenceObserved = false;
            DB::listen(function (QueryExecuted $query) use ($probe, &$probeAttempted, &$permissionFenceObserved): void {
                $sql = strtolower($query->sql);
                if ($probeAttempted || ! str_contains($sql, 'rol_permisos') || ! str_contains($sql, 'for update')) {
                    return;
                }
                $probeAttempted = true;
                try {
                    $probe->table($this->table('rol_permisos'))->where([
                        'rol_codigo' => 'administrador',
                        'permiso_codigo' => 'gobierno.ver',
                    ])->update(['permiso_codigo' => $probe->raw('permiso_codigo')]);
                } catch (QueryException $exception) {
                    $permissionFenceObserved = ($exception->errorInfo[0] ?? null) === '55P03';
                    if (! $permissionFenceObserved) {
                        throw $exception;
                    }
                }
            });
            try {
                app(GenerarExportacionAuditoriaAction::class)->execute($request['id']);
            } finally {
                DB::disconnect($probeName);
            }
            $export = ExportacionAuditoriaEloquentModel::query()->findOrFail($request['id']);
            $this->assertSame('completada', $export->estado->value);
            $this->assertSame(5, $export->total_eventos);
            $this->assertTrue($probeAttempted);
            $this->assertTrue($permissionFenceObserved);
            Storage::disk('audit-exports')->assertExists($export->ruta_privada);

            $cutoff = CarbonImmutable::now('UTC')->startOfSecond()->addSecond();
            $spaceId = DB::table($this->table('espacios_comunes'))->where('edificio_id', $building->id())->value('id');
            foreach ([$cutoff->subMicrosecond(), $cutoff->addMicrosecond()] as $createdAt) {
                DB::table($this->table('bitacora_areas_comunes'))->insert([
                    'id' => (string) Str::uuid(),
                    'edificio_id' => $building->id(),
                    'espacio_comun_id' => $spaceId,
                    'regla_id' => null,
                    'reserva_id' => null,
                    'tipo' => 'espacio_actualizado',
                    'actor_user_id' => $administrator->id,
                    'detalle' => null,
                    'created_at' => $createdAt->format('Y-m-d H:i:s.uP'),
                ]);
            }
            $this->assertSame(2, DB::table($this->table('bitacora_areas_comunes'))
                ->where('edificio_id', $building->id())->where('tipo', 'espacio_actualizado')->count());
            $this->assertSame(1, DB::table($this->table('bitacora_areas_comunes'))
                ->where('edificio_id', $building->id())->where('tipo', 'espacio_actualizado')
                ->where('created_at', '>=', $cutoff->subSecond())->where('created_at', '<', $cutoff)->count());
            $closedSecond = app(AuditoriaRepositoryInterface::class)->exportChunkForUser($administrator->id, [
                'edificios' => [$building->id()],
                'fuente' => 'areas_comunes',
                'tipo' => 'espacio_actualizado',
                'desde_utc' => $cutoff->subSecond()->toIso8601ZuluString(),
                'hasta_utc' => $cutoff->addSecond()->toIso8601ZuluString(),
                'corte_at' => $cutoff->toIso8601ZuluString(),
            ], null, 10);
            $this->assertCount(1, $closedSecond['items']);
        } finally {
            CarbonImmutable::setTestNow();
            DB::rollBack();
        }
    }

    public function test_export_migrations_roll_back_on_postgresql_without_persisting_schema_changes(): void
    {
        $this->requireLocalPostgres();

        DB::beginTransaction();
        try {
            if (! DB::table($this->table('permisos'))->where('codigo', 'gobierno.exportar')->exists()) {
                $permission = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_000000_add_gobierno_export_permission.php');
                $permission->up();
            }
            if (! Schema::hasTable($this->table('exportaciones_auditoria'))) {
                $tables = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_010000_create_gobierno_export_tables.php');
                $tables->up();
                $hardening = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_020000_harden_gobierno_export_integrity.php');
                $hardening->up();
            } else {
                $tables = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_010000_create_gobierno_export_tables.php');
                $hardening = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_020000_harden_gobierno_export_integrity.php');
            }
            if (DB::table($this->table('exportaciones_auditoria'))->exists()) {
                $this->markTestSkipped('El PostgreSQL local contiene historial de exportaciones y no se alterará.');
            }
            $permission ??= require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_000000_add_gobierno_export_permission.php');

            DB::table($this->table('rol_permisos'))->insert([
                'rol_codigo' => 'consulta',
                'permiso_codigo' => 'gobierno.exportar',
            ]);
            try {
                $hardening->down();
                $this->fail('El rollback PostgreSQL modificó protecciones antes de completar el preflight.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('otros roles', $exception->getMessage());
            }
            $this->assertSame(1, (int) DB::scalar(<<<'SQL'
                SELECT COUNT(*)
                FROM pg_trigger
                WHERE tgname = 'exportacion_auditoria_guard' AND NOT tgisinternal
                SQL));
            DB::table($this->table('rol_permisos'))->where([
                'rol_codigo' => 'consulta',
                'permiso_codigo' => 'gobierno.exportar',
            ])->delete();

            $hardening->down();
            $tables->down();
            $permission->down();

            $this->assertFalse(Schema::hasTable($this->table('exportaciones_auditoria')));
            $this->assertFalse(DB::table($this->table('permisos'))->where('codigo', 'gobierno.exportar')->exists());
        } finally {
            DB::rollBack();
        }
    }

    public function test_export_snapshot_is_repeatable_across_a_concurrent_postgresql_commit(): void
    {
        $this->requireLocalPostgres();
        $table = 'gobierno_snapshot_'.strtolower(Str::random(12));
        $qualified = 'public.'.$table;
        $writerName = 'gobierno_snapshot_writer';
        config(["database.connections.{$writerName}" => config('database.connections.pgsql')]);
        DB::purge($writerName);
        $writer = DB::connection($writerName);

        DB::statement("CREATE TABLE {$qualified} (id integer PRIMARY KEY)");
        try {
            DB::table($qualified)->insert(['id' => 1]);
            $counts = app(AuditoriaRepositoryInterface::class)->withinConsistentExportSnapshot(
                function () use ($qualified, $writer): array {
                    $before = DB::table($qualified)->count();
                    $writer->table($qualified)->insert(['id' => 2]);
                    $after = DB::table($qualified)->count();

                    return [$before, $after];
                },
            );

            $this->assertSame([1, 1], $counts);
            $this->assertSame(2, DB::table($qualified)->count());
        } finally {
            DB::disconnect($writerName);
            DB::statement("DROP TABLE IF EXISTS {$qualified}");
        }
    }

    private function table(string $name): string
    {
        return config('database.application_schema').'.'.$name;
    }

    private function requireLocalPostgres(): void
    {
        if (env('GOBIERNO_PG_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requiere GOBIERNO_PG_INTEGRATION=1 y PostgreSQL local de desarrollo.');
        }
        $connection = (array) config('database.connections.pgsql');
        if (config('database.default') !== 'pgsql'
            || ! in_array((string) ($connection['host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true)
            || (bool) DB::scalar('SELECT pg_is_in_recovery()')) {
            throw new RuntimeException('La integracion de Gobierno solo admite PostgreSQL local primario.');
        }
    }

    private function createNativeEvents(UserEloquentModel $actor, string $buildingId): void
    {
        $this->actingAs($actor)->post(route('operaciones.store', $buildingId), [
            'tipo' => 'incidencia', 'titulo' => 'Incidencia PostgreSQL',
            'descripcion' => 'Fixture transaccional.', 'prioridad' => 'alta', 'fecha_objetivo' => null,
            'torre_id' => null, 'piso_id' => null, 'departamento_id' => null,
            'parqueadero_id' => null, 'bodega_id' => null, 'ubicacion_detalle' => 'Area tecnica',
            'reportante_residente_id' => null, 'proveedor_id' => null, 'contrato_id' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($actor)->post(route('mantenimiento-preventivo.store', $buildingId), [
            'codigo' => 'PLAN-PG-01', 'titulo' => 'Plan PostgreSQL',
            'descripcion' => 'Fixture transaccional.', 'prioridad' => 'media',
            'unidad_recurrencia' => 'mensual', 'intervalo_recurrencia' => 1, 'dias_anticipacion' => 2,
            'torre_id' => null, 'piso_id' => null, 'departamento_id' => null,
            'parqueadero_id' => null, 'bodega_id' => null, 'ubicacion_detalle' => 'Area tecnica',
            'proveedor_id' => null, 'contrato_id' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($actor)->post(route('areas-comunes.store', $buildingId), [
            'codigo' => 'AREA-PG-01', 'nombre' => 'Area PostgreSQL',
            'descripcion' => 'Fixture transaccional.', 'capacidad' => 20,
            'torre_id' => null, 'piso_id' => null, 'ubicacion_detalle' => 'Planta baja',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($actor)->post(route('comunicados.store', $buildingId), [
            'titulo' => 'Comunicado PostgreSQL', 'cuerpo' => 'Fixture transaccional.',
            'audiencias' => ['propietarios'],
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
}
