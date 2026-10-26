<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Gobierno\Application\Actions\GenerarExportacionAuditoriaAction;
use Src\Gobierno\Application\Actions\LimpiarExportacionesAuditoriaAction;
use Src\Gobierno\Application\Actions\SolicitarExportacionAuditoriaAction;
use Src\Gobierno\Domain\Contracts\ExportacionAuditoriaRepositoryInterface;
use Src\Gobierno\Domain\Exceptions\AutorizacionExportacionRevocada;
use Src\Gobierno\Domain\Exceptions\LimiteFilasExportacionExcedido;
use Src\Gobierno\Infrastructure\Jobs\GenerarExportacionAuditoriaJob;
use Src\Gobierno\Infrastructure\Models\ExportacionAuditoriaEloquentModel;
use Tests\TestCase;

final class GobiernoExportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_csv_request_is_idempotent_generated_privately_and_downloaded_with_integrity_audit(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        Storage::fake('audit-exports');
        [$administrator, $buildingId] = $this->fixture('=2+3');
        $this->createNativeEvents($administrator, $buildingId);
        CarbonImmutable::setTestNow('2026-10-22T15:00:01Z');
        $data = $this->requestData($buildingId, 'csv');

        $first = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $data);
        $second = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $data);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('exportaciones_auditoria', 1);
        Queue::assertPushed(GenerarExportacionAuditoriaJob::class, 1);
        Queue::assertPushed(GenerarExportacionAuditoriaJob::class, static fn (GenerarExportacionAuditoriaJob $job): bool => $job->connection === 'database-exports' && $job->queue === 'gobierno-exportaciones');

        app(GenerarExportacionAuditoriaAction::class)->execute($first['id']);
        $export = ExportacionAuditoriaEloquentModel::query()->findOrFail($first['id']);
        $this->assertSame('completada', $export->estado->value);
        $this->assertSame(5, $export->total_eventos);
        Storage::disk('audit-exports')->assertExists($export->ruta_privada);
        $csv = Storage::disk('audit-exports')->get($export->ruta_privada);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=2+3", $csv);
        $this->assertStringNotContainsString(',=2+3,', $csv);

        $download = app(ExportacionAuditoriaRepositoryInterface::class)->downloadForUser($administrator->id, $first['id']);
        $this->assertFileExists($download['localPath']);
        $this->assertStringStartsWith(
            realpath(storage_path('app/private')).DIRECTORY_SEPARATOR,
            (string) realpath($download['localPath']),
        );
        $this->assertSame($csv, file_get_contents($download['localPath']));
        unlink($download['localPath']);
        $this->assertDatabaseHas('bitacora_exportacion_auditoria', [
            'exportacion_id' => $first['id'],
            'tipo' => 'descargada',
            'actor_user_id' => $administrator->id,
        ]);
    }

    public function test_idempotency_key_is_canonical_and_cannot_be_reused_for_another_request(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        [$administrator, $buildingId] = $this->fixture('Edificio Idempotente');
        $data = $this->requestData($buildingId, 'csv');
        $data['idempotency_key'] = strtoupper($data['idempotency_key']);
        $first = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $data);
        $data['idempotency_key'] = strtolower($data['idempotency_key']);
        $second = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $data);

        $this->assertSame($first['id'], $second['id']);
        $this->assertFalse($second['created']);
        $this->assertDatabaseCount('exportaciones_auditoria', 1);

        $data['formato'] = 'pdf';
        $this->expectException(ValidationException::class);
        app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $data);
    }

    public function test_request_rejects_nonzero_subseconds_and_uses_the_last_closed_utc_second(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00.750000Z');
        Queue::fake();
        [$administrator, $buildingId] = $this->fixture('Edificio Precisión Temporal');
        $data = $this->requestData($buildingId, 'csv');
        $data['desde_utc'] = '2026-10-21T00:00:00.500000Z';

        try {
            app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $data);
            $this->fail('La solicitud aceptó precisión temporal que la persistencia no conserva.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('desde_utc', $exception->errors());
        }

        $data['idempotency_key'] = (string) Str::uuid();
        $data['desde_utc'] = '2026-10-21T00:00:00.000000Z';
        $data['hasta_utc'] = '2026-10-22T15:01:00.000000Z';
        $request = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $data);
        $export = ExportacionAuditoriaEloquentModel::query()->findOrFail($request['id']);

        $this->assertSame('000000', $export->desde_utc->format('u'));
        $this->assertSame('2026-10-22T15:00:00Z', $export->corte_at->utc()->toIso8601ZuluString());
        $this->assertDatabaseCount('exportaciones_auditoria', 1);
    }

    public function test_pdf_is_bounded_and_failure_history_does_not_store_exception_details(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        Storage::fake('audit-exports');
        config()->set('gobierno.exports.pdf_max_rows', 1);
        config()->set('gobierno.exports.max_attempts', 1);
        [$administrator, $buildingId] = $this->fixture('Edificio PDF');
        $this->createNativeEvents($administrator, $buildingId);
        CarbonImmutable::setTestNow('2026-10-22T15:00:01Z');
        $request = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $this->requestData($buildingId, 'pdf'));

        try {
            app(GenerarExportacionAuditoriaAction::class)->execute($request['id']);
            $this->fail('La exportación PDF debía respetar el límite configurado.');
        } catch (LimiteFilasExportacionExcedido) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('exportaciones_auditoria', [
            'id' => $request['id'],
            'estado' => 'fallida',
            'error_codigo' => 'LimiteFilasExportacionExcedido',
        ]);
        $this->assertDatabaseHas('bitacora_exportacion_auditoria', [
            'exportacion_id' => $request['id'],
            'tipo' => 'fallida',
            'error_codigo' => 'LimiteFilasExportacionExcedido',
        ]);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('bitacora_exportacion_auditoria', 'error_detalle'));
        $this->assertSame([], Storage::disk('audit-exports')->allFiles());
    }

    public function test_generation_fails_terminally_when_a_captured_source_permission_is_revoked(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        Storage::fake('audit-exports');
        [$administrator, $buildingId] = $this->fixture('Edificio Revocado');
        $data = $this->requestData($buildingId, 'csv');
        $data['fuente'] = 'accesos';
        $request = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $data);
        DB::table('rol_permisos')->where([
            'rol_codigo' => RolEdificio::ADMINISTRADOR->value,
            'permiso_codigo' => PermisoEdificio::MIEMBROS_VER->value,
        ])->delete();

        $this->expectException(AutorizacionExportacionRevocada::class);
        try {
            app(GenerarExportacionAuditoriaAction::class)->execute($request['id']);
        } finally {
            $this->assertDatabaseHas('exportaciones_auditoria', [
                'id' => $request['id'],
                'estado' => 'fallida',
                'error_codigo' => 'AutorizacionExportacionRevocada',
            ]);
        }
    }

    public function test_generation_fails_terminally_when_export_permission_is_revoked(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        Storage::fake('audit-exports');
        [$administrator, $buildingId] = $this->fixture('Edificio Gobierno Revocado');
        $request = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $this->requestData($buildingId, 'csv'));
        DB::table('rol_permisos')->where([
            'rol_codigo' => RolEdificio::ADMINISTRADOR->value,
            'permiso_codigo' => PermisoEdificio::GOBIERNO_EXPORTAR->value,
        ])->delete();

        $this->expectException(AutorizacionExportacionRevocada::class);
        try {
            app(GenerarExportacionAuditoriaAction::class)->execute($request['id']);
        } finally {
            $this->assertDatabaseHas('exportaciones_auditoria', [
                'id' => $request['id'],
                'estado' => 'fallida',
                'error_codigo' => 'AutorizacionExportacionRevocada',
            ]);
        }
    }

    public function test_pdf_is_generated_without_external_content_and_stored_privately(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        Storage::fake('audit-exports');
        [$administrator, $buildingId] = $this->fixture('Edificio PDF seguro');
        CarbonImmutable::setTestNow('2026-10-22T15:00:01Z');
        $request = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $this->requestData($buildingId, 'pdf'));

        app(GenerarExportacionAuditoriaAction::class)->execute($request['id']);

        $export = ExportacionAuditoriaEloquentModel::query()->findOrFail($request['id']);
        $this->assertSame('application/pdf', $export->mime_type);
        $this->assertStringStartsWith('%PDF-', Storage::disk('audit-exports')->get($export->ruta_privada));
        $this->assertSame(1, $export->total_eventos);
        $this->assertDatabaseMissing('limpieza_archivos_exportacion', ['exportacion_id' => $request['id']]);
    }

    public function test_expired_export_is_marked_and_its_private_file_is_deleted(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        Storage::fake('audit-exports');
        [$administrator, $buildingId] = $this->fixture('Edificio Limpieza');
        $request = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $this->requestData($buildingId, 'csv'));
        app(GenerarExportacionAuditoriaAction::class)->execute($request['id']);
        $path = ExportacionAuditoriaEloquentModel::query()->findOrFail($request['id'])->ruta_privada;

        CarbonImmutable::setTestNow('2026-10-23T15:00:01Z');
        $result = app(LimpiarExportacionesAuditoriaAction::class)->execute();

        $this->assertSame(['expired' => 1, 'deleted' => 1, 'errors' => 0], $result);
        Storage::disk('audit-exports')->assertMissing($path);
        $this->assertDatabaseHas('exportaciones_auditoria', [
            'id' => $request['id'],
            'estado' => 'expirada',
        ]);
        $this->assertDatabaseHas('bitacora_exportacion_auditoria', ['exportacion_id' => $request['id'], 'tipo' => 'archivo_eliminado']);
    }

    /** @return array{UserEloquentModel, string} */
    private function fixture(string $buildingName): array
    {
        $administrator = UserEloquentModel::factory()->create();
        $building = app(CreateEdificioAction::class)->execute([
            'nombre' => $buildingName,
            'ruc' => (string) random_int(1000000000000, 9999999999999),
            'direccion' => 'Direccion',
            'ciudad' => 'Quito',
            'timezone' => 'America/Guayaquil',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $administrator->id);

        return [$administrator, $building->id()];
    }

    /** @return array<string, mixed> */
    private function requestData(string $buildingId, string $format): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'formato' => $format,
            'edificios' => [$buildingId],
            'fuente' => null,
            'tipo' => null,
            'desde_utc' => '2026-10-21T00:00:00Z',
            'hasta_utc' => '2026-10-23T00:00:00Z',
        ];
    }

    private function createNativeEvents(UserEloquentModel $actor, string $buildingId): void
    {
        $this->actingAs($actor)->post(route('operaciones.store', $buildingId), [
            'tipo' => 'incidencia', 'titulo' => 'Incidencia exportable',
            'descripcion' => 'Fixture.', 'prioridad' => 'alta', 'fecha_objetivo' => null,
            'torre_id' => null, 'piso_id' => null, 'departamento_id' => null,
            'parqueadero_id' => null, 'bodega_id' => null, 'ubicacion_detalle' => 'Area tecnica',
            'reportante_residente_id' => null, 'proveedor_id' => null, 'contrato_id' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($actor)->post(route('mantenimiento-preventivo.store', $buildingId), [
            'codigo' => 'PLAN-EXP-02', 'titulo' => 'Plan exportable', 'descripcion' => 'Fixture.',
            'prioridad' => 'media', 'unidad_recurrencia' => 'mensual', 'intervalo_recurrencia' => 1,
            'dias_anticipacion' => 2, 'torre_id' => null, 'piso_id' => null, 'departamento_id' => null,
            'parqueadero_id' => null, 'bodega_id' => null, 'ubicacion_detalle' => 'Area tecnica',
            'proveedor_id' => null, 'contrato_id' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($actor)->post(route('areas-comunes.store', $buildingId), [
            'codigo' => 'AREA-EXP-02', 'nombre' => 'Area exportable', 'descripcion' => 'Fixture.',
            'capacidad' => 20, 'torre_id' => null, 'piso_id' => null, 'ubicacion_detalle' => 'Planta baja',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($actor)->post(route('comunicados.store', $buildingId), [
            'titulo' => 'Comunicado exportable', 'cuerpo' => 'Fixture.', 'audiencias' => ['propietarios'],
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
}
