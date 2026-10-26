<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Gobierno\Application\Actions\GenerarExportacionAuditoriaAction;
use Src\Gobierno\Application\Actions\SolicitarExportacionAuditoriaAction;
use Src\Gobierno\Domain\Contracts\ExportacionAuditoriaRepositoryInterface;
use Src\Gobierno\Infrastructure\Exports\ExportacionAuditoriaTemporaryFiles;
use Src\Gobierno\Infrastructure\Models\ExportacionAuditoriaEloquentModel;
use Tests\TestCase;

final class GobiernoExportReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_active_lease_fences_duplicate_and_stale_workers_then_allows_recovery(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        [$user, $buildingId] = $this->fixture();
        $id = app(SolicitarExportacionAuditoriaAction::class)->execute($user->id, $this->payload($buildingId))['id'];
        $repository = app(ExportacionAuditoriaRepositoryInterface::class);

        $first = $repository->claim($id);
        $this->assertNotNull($first);
        $this->assertNull($repository->claim($id));
        CarbonImmutable::setTestNow('2026-10-22T15:15:01Z');
        $this->assertFalse($repository->renewProgress($id, $first['leaseToken'], 10));
        $recovered = $repository->claim($id);

        $this->assertNotNull($recovered);
        $this->assertNotSame($first['leaseToken'], $recovered['leaseToken']);
        $this->assertTrue($repository->renewProgress($id, $recovered['leaseToken'], 1));
        $this->assertDatabaseHas('bitacora_exportacion_auditoria', [
            'exportacion_id' => $id,
            'tipo' => 'lease_recuperado',
            'intento' => 2,
        ]);
    }

    public function test_download_rejects_same_size_file_tampering(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        Storage::fake('audit-exports');
        [$user, $buildingId] = $this->fixture();
        $id = app(SolicitarExportacionAuditoriaAction::class)->execute($user->id, $this->payload($buildingId))['id'];
        app(GenerarExportacionAuditoriaAction::class)->execute($id);
        $export = ExportacionAuditoriaEloquentModel::query()->findOrFail($id);
        $content = Storage::disk('audit-exports')->get($export->ruta_privada);
        Storage::disk('audit-exports')->put($export->ruta_privada, str_repeat('X', strlen($content)));

        $this->actingAs($user)->get(route('gobierno.auditoria.exportaciones.download', $id))->assertConflict();
        $this->assertDatabaseMissing('bitacora_exportacion_auditoria', ['exportacion_id' => $id, 'tipo' => 'descargada']);
    }

    public function test_scheduler_registers_recovery_and_cleanup_tasks(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(static fn ($event): bool => $event->description === 'gobierno:despachar-exportaciones'));
        $this->assertTrue($events->contains(static fn ($event): bool => str_contains((string) $event->command, 'gobierno:limpiar-exportaciones')));
    }

    public function test_cleanup_fences_an_expired_worker_before_deleting_its_partial_file(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        Storage::fake('audit-exports');
        [$user, $buildingId] = $this->fixture();
        $id = app(SolicitarExportacionAuditoriaAction::class)->execute($user->id, $this->payload($buildingId))['id'];
        $repository = app(ExportacionAuditoriaRepositoryInterface::class);
        $claim = $repository->claim($id);
        $path = "exports/{$id}/{$claim['leaseToken']}.csv";
        $repository->registerCleanupIntent($id, $claim['leaseToken'], 'audit-exports', $path);
        Storage::disk('audit-exports')->put($path, 'archivo parcial');

        CarbonImmutable::setTestNow('2026-10-22T15:15:01Z');
        $firstCleanup = $repository->cleanupExpired();

        $this->assertSame(['expired' => 0, 'deleted' => 0, 'errors' => 0], $firstCleanup);
        Storage::disk('audit-exports')->assertExists($path);
        $this->assertFalse($repository->renewProgress($id, $claim['leaseToken'], 1));
        $this->assertDatabaseHas('exportaciones_auditoria', ['id' => $id, 'estado' => 'pendiente', 'lease_token' => null]);

        CarbonImmutable::setTestNow('2026-10-22T15:30:01Z');
        $secondCleanup = $repository->cleanupExpired();

        $this->assertSame(['expired' => 0, 'deleted' => 1, 'errors' => 0], $secondCleanup);
        Storage::disk('audit-exports')->assertMissing($path);
    }

    public function test_cleanup_removes_abandoned_private_temporary_files(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        config()->set('gobierno.exports.temp_max_age_seconds', 900);
        $temporary = app(ExportacionAuditoriaTemporaryFiles::class)->create('abandoned-test');
        touch($temporary, CarbonImmutable::now('UTC')->subSeconds(901)->getTimestamp());

        try {
            $result = app(ExportacionAuditoriaRepositoryInterface::class)->cleanupExpired();

            $this->assertFileDoesNotExist($temporary);
            $this->assertSame(0, $result['errors']);
        } finally {
            @unlink($temporary);
        }
    }

    public function test_internal_generation_errors_are_reduced_to_a_public_code(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        config()->set('gobierno.exports.max_attempts', 1);
        Queue::fake();
        [$user, $buildingId] = $this->fixture();
        $id = app(SolicitarExportacionAuditoriaAction::class)->execute($user->id, $this->payload($buildingId))['id'];
        $repository = app(ExportacionAuditoriaRepositoryInterface::class);
        $claim = $repository->claim($id);

        $repository->fail($id, $claim['leaseToken'], new RuntimeException('detalle sensible interno'));

        $this->assertDatabaseHas('exportaciones_auditoria', [
            'id' => $id,
            'estado' => 'fallida',
            'error_codigo' => 'ErrorGeneracion',
        ]);
        $this->assertDatabaseMissing('bitacora_exportacion_auditoria', [
            'exportacion_id' => $id,
            'error_codigo' => 'RuntimeException',
        ]);
    }

    /** @return array{UserEloquentModel, string} */
    private function fixture(): array
    {
        $user = UserEloquentModel::factory()->create();
        $building = app(CreateEdificioAction::class)->execute([
            'nombre' => 'Edificio Confiable',
            'ruc' => (string) random_int(1000000000000, 9999999999999),
            'direccion' => 'Direccion',
            'ciudad' => 'Quito',
            'timezone' => 'America/Guayaquil',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $user->id);

        return [$user, $building->id()];
    }

    /** @return array<string, mixed> */
    private function payload(string $buildingId): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'formato' => 'csv',
            'edificios' => [$buildingId],
            'fuente' => null,
            'tipo' => null,
            'desde_utc' => '2026-10-21T00:00:00Z',
            'hasta_utc' => '2026-10-23T00:00:00Z',
        ];
    }
}
