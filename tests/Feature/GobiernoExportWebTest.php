<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Gobierno\Application\Actions\GenerarExportacionAuditoriaAction;
use Src\Gobierno\Application\Actions\SolicitarExportacionAuditoriaAction;
use Src\Gobierno\Domain\Contracts\ExportacionAuditoriaRepositoryInterface;
use Tests\TestCase;

final class GobiernoExportWebTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_authorized_request_is_queued_and_exposed_without_private_storage_metadata(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        [$administrator, $buildingId] = $this->fixture('Edificio Web');

        $response = $this->actingAs($administrator)->post(route('gobierno.auditoria.exportaciones.store'), $this->payload($buildingId));

        $response->assertRedirect()->assertSessionHasNoErrors();
        $exportId = DB::table('exportaciones_auditoria')->value('id');
        $this->actingAs($administrator)->get(route('gobierno.auditoria.index', ['edificios' => [$buildingId]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('puedeExportar', true)
                ->where('alcanceVigente', true)
                ->has('huellaAlcance')
                ->where('limitesExportacion.maxRangeDays', 90)
                ->has('exportaciones', 1)
                ->where('exportaciones.0.id', $exportId)
                ->where('exportaciones.0.status', 'pendiente')
                ->missing('exportaciones.0.ruta_privada')
                ->missing('exportaciones.0.sha256'));
    }

    public function test_request_rejects_non_utc_excessive_ranges_and_cross_building_access(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        [$administrator, $buildingId] = $this->fixture('Edificio Propio');
        [, $foreignBuildingId] = $this->fixture('Edificio Ajeno');

        $nonUtc = $this->payload($buildingId);
        $nonUtc['desde_utc'] = '2026-10-21T00:00:00-05:00';
        $this->actingAs($administrator)->postJson(route('gobierno.auditoria.exportaciones.store'), $nonUtc)
            ->assertUnprocessable()->assertJsonValidationErrors('desdeUtc');

        $tooLong = $this->payload($buildingId);
        $tooLong['desde_utc'] = '2026-01-01T00:00:00Z';
        $this->actingAs($administrator)->postJson(route('gobierno.auditoria.exportaciones.store'), $tooLong)
            ->assertUnprocessable()->assertJsonValidationErrors('hastaUtc');

        $crossBuilding = $this->payload($foreignBuildingId);
        $this->actingAs($administrator)->postJson(route('gobierno.auditoria.exportaciones.store'), $crossBuilding)
            ->assertForbidden();

        $incompatibleType = $this->payload($buildingId);
        $incompatibleType['fuente'] = 'accesos';
        $incompatibleType['tipo'] = 'comunicado_creado';
        $this->actingAs($administrator)->postJson(route('gobierno.auditoria.exportaciones.store'), $incompatibleType)
            ->assertUnprocessable()->assertJsonValidationErrors('tipo');

        $fractional = $this->payload($buildingId);
        $fractional['desde_utc'] = '2026-10-21T00:00:00.000001Z';
        $this->actingAs($administrator)->postJson(route('gobierno.auditoria.exportaciones.store'), $fractional)
            ->assertUnprocessable()->assertJsonValidationErrors('desdeUtc');
        $this->assertDatabaseCount('exportaciones_auditoria', 0);
    }

    public function test_download_revalidates_owner_and_current_building_permission(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        Storage::fake('audit-exports');
        [$administrator, $buildingId] = $this->fixture('Edificio Descarga');
        $payload = $this->payload($buildingId);
        $this->actingAs($administrator)->post(route('gobierno.auditoria.exportaciones.store'), $payload)->assertRedirect();
        $exportId = DB::table('exportaciones_auditoria')->value('id');
        app(GenerarExportacionAuditoriaAction::class)->execute($exportId);

        $download = $this->actingAs($administrator)->get(route('gobierno.auditoria.exportaciones.download', $exportId))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('private', (string) $download->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('cache-control'));
        $outsider = UserEloquentModel::factory()->create();
        $this->actingAs($outsider)->get(route('gobierno.auditoria.exportaciones.download', $exportId))->assertNotFound();

        DB::table('rol_permisos')->where([
            'rol_codigo' => RolEdificio::ADMINISTRADOR->value,
            'permiso_codigo' => PermisoEdificio::GOBIERNO_EXPORTAR->value,
        ])->delete();
        $this->actingAs($administrator)->get(route('gobierno.auditoria.exportaciones.download', $exportId))->assertForbidden();
    }

    public function test_download_and_recent_list_revalidate_native_source_permission(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        Storage::fake('audit-exports');
        [$administrator, $buildingId] = $this->fixture('Edificio Fuente Revocada');
        $payload = $this->payload($buildingId);
        $payload['fuente'] = 'accesos';
        $this->actingAs($administrator)->post(route('gobierno.auditoria.exportaciones.store'), $payload)->assertRedirect();
        $exportId = DB::table('exportaciones_auditoria')->value('id');
        app(GenerarExportacionAuditoriaAction::class)->execute($exportId);

        DB::table('rol_permisos')->where([
            'rol_codigo' => RolEdificio::ADMINISTRADOR->value,
            'permiso_codigo' => PermisoEdificio::MIEMBROS_VER->value,
        ])->delete();

        $this->actingAs($administrator)->get(route('gobierno.auditoria.exportaciones.download', $exportId))->assertForbidden();
        $this->actingAs($administrator)->get(route('gobierno.auditoria.index', ['edificios' => [$buildingId]]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('exportaciones', 0));
    }

    public function test_download_revalidates_detail_permission_captured_by_the_export(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        Queue::fake();
        Storage::fake('audit-exports');
        [$administrator, $buildingId] = $this->fixture('Edificio Detalle Revocado');
        $this->actingAs($administrator)->post(route('gobierno.auditoria.exportaciones.store'), $this->payload($buildingId))->assertRedirect();
        $exportId = DB::table('exportaciones_auditoria')->value('id');
        app(GenerarExportacionAuditoriaAction::class)->execute($exportId);

        DB::table('rol_permisos')->where([
            'rol_codigo' => RolEdificio::ADMINISTRADOR->value,
            'permiso_codigo' => PermisoEdificio::GOBIERNO_DETALLES_VER->value,
        ])->delete();

        $this->actingAs($administrator)->get(route('gobierno.auditoria.exportaciones.download', $exportId))->assertForbidden();
    }

    public function test_partial_poll_changes_scope_fingerprint_when_visible_permissions_are_revoked(): void
    {
        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        [$administrator, $buildingId] = $this->fixture('Edificio Poll Seguro');
        $headers = [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Gobierno/Auditoria/index',
            'X-Inertia-Partial-Data' => 'exportaciones,alcanceVigente,huellaAlcance,puedeExportar',
        ];
        $url = route('gobierno.auditoria.index', ['edificios' => [$buildingId]]);
        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)
            ->version(\Illuminate\Http\Request::create($url));
        if ($version !== null) {
            $headers['X-Inertia-Version'] = $version;
        }
        $before = $this->actingAs($administrator)->withHeaders($headers)->get($url)->assertOk();
        $fingerprint = $before->json('props.huellaAlcance');

        DB::table('rol_permisos')->where([
            'rol_codigo' => RolEdificio::ADMINISTRADOR->value,
            'permiso_codigo' => PermisoEdificio::GOBIERNO_DETALLES_VER->value,
        ])->delete();

        $after = $this->actingAs($administrator)->withHeaders($headers)->get($url)->assertOk();
        $after->assertJsonPath('props.alcanceVigente', true)
            ->assertJsonPath('props.puedeExportar', true)
            ->assertJsonMissingPath('props.eventos');
        $this->assertNotSame($fingerprint, $after->json('props.huellaAlcance'));
    }

    public function test_recent_list_scans_past_revoked_newer_exports(): void
    {
        Queue::fake();
        config()->set('gobierno.exports.max_active_per_user', 100);
        config()->set('gobierno.exports.max_daily_per_user', 100);
        CarbonImmutable::setTestNow('2026-10-22T14:00:00Z');
        [$administrator, $buildingId] = $this->fixture('Edificio Historial Autorizado');
        $oldPayload = $this->payload($buildingId);
        $oldPayload['fuente'] = 'operaciones';
        $old = app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $oldPayload);

        CarbonImmutable::setTestNow('2026-10-22T15:00:00Z');
        for ($index = 0; $index < 50; $index++) {
            $newerPayload = $this->payload($buildingId);
            $newerPayload['fuente'] = 'accesos';
            app(SolicitarExportacionAuditoriaAction::class)->execute($administrator->id, $newerPayload);
        }
        DB::table('rol_permisos')->where([
            'rol_codigo' => RolEdificio::ADMINISTRADOR->value,
            'permiso_codigo' => PermisoEdificio::MIEMBROS_VER->value,
        ])->delete();

        $recent = app(ExportacionAuditoriaRepositoryInterface::class)->recentForUser($administrator->id, 1);

        $this->assertCount(1, $recent);
        $this->assertSame($old['id'], $recent[0]['id']);
    }

    /** @return array{UserEloquentModel, string} */
    private function fixture(string $name): array
    {
        $administrator = UserEloquentModel::factory()->create();
        $building = app(CreateEdificioAction::class)->execute([
            'nombre' => $name,
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
