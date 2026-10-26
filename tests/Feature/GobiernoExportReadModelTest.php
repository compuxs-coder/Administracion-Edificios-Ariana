<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gobierno\Domain\Contracts\AuditoriaRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class GobiernoExportReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_cursor_reads_each_native_event_once_with_a_stable_cutoff(): void
    {
        CarbonImmutable::setTestNow('2026-10-21T12:00:00Z');
        $administrator = UserEloquentModel::factory()->create();
        $building = app(CreateEdificioAction::class)->execute([
            'nombre' => 'Edificio Cursor',
            'ruc' => '1799000000001',
            'direccion' => 'Direccion',
            'ciudad' => 'Quito',
            'timezone' => 'America/Guayaquil',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $administrator->id);
        $this->createNativeEvents($administrator, $building->id());

        $repository = app(AuditoriaRepositoryInterface::class);
        $filters = [
            'edificios' => [$building->id()],
            'fuente' => null,
            'tipo' => null,
            'desde_utc' => '2026-10-20T00:00:00Z',
            'hasta_utc' => '2026-10-24T00:00:00Z',
            'corte_at' => '2026-10-23T23:59:59Z',
        ];
        $items = [];
        $cursor = null;
        do {
            $chunk = $repository->exportChunkForUser($administrator->id, $filters, $cursor, 2);
            $items = [...$items, ...$chunk['items']];
            $cursor = $chunk['nextCursor'];
        } while ($chunk['hasMore']);

        $this->assertCount(5, $items);
        $this->assertCount(5, array_unique(array_column($items, 'id')));
        $this->assertEqualsCanonicalizing(
            ['accesos', 'operaciones', 'mantenimiento', 'areas_comunes', 'comunicacion'],
            array_column($items, 'fuente'),
        );
        $this->assertNull($cursor);
    }

    public function test_export_scope_snapshots_source_and_detail_permissions_per_building(): void
    {
        $administrator = UserEloquentModel::factory()->create();
        $building = app(CreateEdificioAction::class)->execute([
            'nombre' => 'Edificio Alcance',
            'ruc' => '1799000000002',
            'direccion' => 'Direccion',
            'ciudad' => 'Quito',
            'timezone' => 'America/Guayaquil',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $administrator->id);

        $scope = app(AuditoriaRepositoryInterface::class)->exportScopeForUser(
            $administrator->id,
            [$building->id()],
            'areas_comunes',
            'reserva_creada',
        );

        $this->assertSame($building->id(), $scope[0]['id']);
        $this->assertSame('America/Guayaquil', $scope[0]['timezone']);
        $this->assertSame([PermisoEdificio::RESERVAS_AREAS_COMUNES_VER->value], $scope[0]['permisosFuente']);
        $this->assertTrue($scope[0]['detalleIncluido']);
    }

    public function test_sqlite_applies_same_day_range_and_cutoff_as_instants(): void
    {
        CarbonImmutable::setTestNow('2026-10-21T12:00:00Z');
        $administrator = UserEloquentModel::factory()->create();
        $building = app(CreateEdificioAction::class)->execute([
            'nombre' => 'Edificio Rango Mismo Dia',
            'ruc' => '1799000000004',
            'direccion' => 'Direccion',
            'ciudad' => 'Quito',
            'timezone' => 'America/Guayaquil',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $administrator->id);
        $this->createNativeEvents($administrator, $building->id());
        $repository = app(AuditoriaRepositoryInterface::class);

        $inside = $repository->exportChunkForUser($administrator->id, [
            'edificios' => [$building->id()],
            'fuente' => null,
            'tipo' => null,
            'desde_utc' => '2026-10-21T11:00:00Z',
            'hasta_utc' => '2026-10-21T13:00:00Z',
            'corte_at' => '2026-10-21T13:00:00Z',
        ], null, 10);
        $afterEnd = $repository->exportChunkForUser($administrator->id, [
            'edificios' => [$building->id()],
            'fuente' => null,
            'tipo' => null,
            'desde_utc' => '2026-10-20T00:00:00Z',
            'hasta_utc' => '2026-10-21T11:00:00Z',
            'corte_at' => '2026-10-21T11:00:00Z',
        ], null, 10);
        $afterCutoff = $repository->exportChunkForUser($administrator->id, [
            'edificios' => [$building->id()],
            'fuente' => null,
            'tipo' => null,
            'desde_utc' => '2026-10-20T00:00:00Z',
            'hasta_utc' => '2026-10-22T00:00:00Z',
            'corte_at' => '2026-10-21T11:00:00Z',
        ], null, 10);
        $spaceId = DB::table('espacios_comunes')->where('edificio_id', $building->id())->value('id');
        foreach (['2026-10-21 11:59:59.500000', '2026-10-21 12:00:00.500000'] as $createdAt) {
            DB::table('bitacora_areas_comunes')->insert([
                'id' => (string) Str::uuid(),
                'edificio_id' => $building->id(),
                'espacio_comun_id' => $spaceId,
                'regla_id' => null,
                'reserva_id' => null,
                'tipo' => 'espacio_actualizado',
                'actor_user_id' => $administrator->id,
                'detalle' => null,
                'created_at' => $createdAt,
            ]);
        }
        $closedSecond = $repository->exportChunkForUser($administrator->id, [
            'edificios' => [$building->id()],
            'fuente' => 'areas_comunes',
            'tipo' => 'espacio_actualizado',
            'desde_utc' => '2026-10-21T11:59:59Z',
            'hasta_utc' => '2026-10-21T12:00:01Z',
            'corte_at' => '2026-10-21T12:00:00Z',
        ], null, 10);

        $this->assertCount(5, $inside['items']);
        $this->assertSame([], $afterEnd['items']);
        $this->assertSame([], $afterCutoff['items']);
        $this->assertCount(1, $closedSecond['items']);
        $this->assertSame('2026-10-21T11:59:59Z', $closedSecond['items'][0]['ocurridoAt']);
    }

    public function test_export_read_model_rejects_a_user_without_export_permission(): void
    {
        $administrator = UserEloquentModel::factory()->create();
        $building = app(CreateEdificioAction::class)->execute([
            'nombre' => 'Edificio Sin Exportar',
            'ruc' => '1799000000003',
            'direccion' => 'Direccion',
            'ciudad' => 'Quito',
            'timezone' => 'America/Guayaquil',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $administrator->id);
        $user = UserEloquentModel::factory()->create();
        $buildingModel = EdificioEloquentModel::query()->findOrFail($building->id());
        $buildingModel->usuarios()->attach($user->id, ['creado_por_user_id' => $administrator->id]);
        DB::table('edificio_usuario_roles')->insert([
            'edificio_id' => $building->id(),
            'user_id' => $user->id,
            'rol_codigo' => RolEdificio::CONSULTA->value,
            'asignado_por_user_id' => $administrator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(HttpException::class);
        app(AuditoriaRepositoryInterface::class)->exportScopeForUser($user->id, [$building->id()], null, null);
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
            'codigo' => 'PLAN-EXP-01', 'titulo' => 'Plan exportable',
            'descripcion' => 'Fixture.', 'prioridad' => 'media',
            'unidad_recurrencia' => 'mensual', 'intervalo_recurrencia' => 1, 'dias_anticipacion' => 2,
            'torre_id' => null, 'piso_id' => null, 'departamento_id' => null,
            'parqueadero_id' => null, 'bodega_id' => null, 'ubicacion_detalle' => 'Area tecnica',
            'proveedor_id' => null, 'contrato_id' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($actor)->post(route('areas-comunes.store', $buildingId), [
            'codigo' => 'AREA-EXP-01', 'nombre' => 'Area exportable',
            'descripcion' => 'Fixture.', 'capacidad' => 20,
            'torre_id' => null, 'piso_id' => null, 'ubicacion_detalle' => 'Planta baja',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($actor)->post(route('comunicados.store', $buildingId), [
            'titulo' => 'Comunicado exportable', 'cuerpo' => 'Fixture.',
            'audiencias' => ['propietarios'],
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

}
