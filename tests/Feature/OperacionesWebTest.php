<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\TorreEloquentModel;
use Src\Gastos\Infrastructure\Models\ContratoProveedorEloquentModel;
use Src\Gastos\Infrastructure\Models\ProveedorEloquentModel;
use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;
use Src\Operaciones\Domain\Enums\EstadoOrdenOperativa;
use Src\Operaciones\Infrastructure\Models\AsignacionOrdenOperativaEloquentModel;
use Src\Operaciones\Infrastructure\Models\BitacoraOrdenOperativaEloquentModel;
use Src\Operaciones\Infrastructure\Models\EvidenciaOrdenOperativaEloquentModel;
use Src\Operaciones\Infrastructure\Models\OrdenOperativaEloquentModel;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Models\TerceroEloquentModel;
use Tests\TestCase;

final class OperacionesWebTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_routes_require_authentication_have_no_delete_and_enforce_role_matrix_and_building_isolation(): void
    {
        [$administrator, $building] = $this->fixture();
        [$otherAdministrator, $otherBuilding] = $this->fixture();
        $operationsManager = UserEloquentModel::factory()->create();
        $viewer = UserEloquentModel::factory()->create();
        $propertyManager = UserEloquentModel::factory()->create();
        $financeManager = UserEloquentModel::factory()->create();
        $this->addMember($building, $operationsManager, RolEdificio::GESTOR_OPERACIONES, $administrator);
        $this->addMember($building, $viewer, RolEdificio::CONSULTA, $administrator);
        $this->addMember($building, $propertyManager, RolEdificio::GESTOR_PROPIEDAD, $administrator);
        $this->addMember($building, $financeManager, RolEdificio::GESTOR_FINANZAS, $administrator);

        $this->get(route('operaciones.index'))->assertRedirect(route('login'));
        $this->get(route('operaciones.create'))->assertRedirect(route('login'));
        $this->post(route('operaciones.store', $building), $this->orderData())->assertRedirect(route('login'));
        foreach (['operaciones.destroy', 'operaciones.evidence.destroy', 'operaciones.actions.destroy'] as $routeName) {
            $this->assertFalse(Route::has($routeName));
        }

        $this->actingAs($operationsManager)->get(route('operaciones.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operacion/index')
                ->has('edificios', 1)
                ->where('edificios.0.id', $building->id));
        $this->actingAs($operationsManager)->get(route('operaciones.create'))->assertOk();
        $this->actingAs($viewer)->get(route('operaciones.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('edificios', 1)
                ->missing('miembros')
                ->missing('residentes')
                ->missing('proveedores')
                ->missing('contratos')
                ->missing('torres')
                ->missing('pisos')
                ->missing('departamentos')
                ->missing('parqueaderos')
                ->missing('bodegas'));
        $this->actingAs($viewer)->get(route('operaciones.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('operaciones.store', $building), $this->orderData())->assertForbidden();
        $this->actingAs($propertyManager)->get(route('operaciones.index'))->assertForbidden();
        $this->actingAs($financeManager)->get(route('operaciones.index'))->assertForbidden();

        $visibleOrder = $this->createOrder($administrator, $building, ['titulo' => 'Orden visible para consulta']);
        $this->actingAs($viewer)->get(route('operaciones.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ordenes.data', 1)
                ->missing('ordenes.data.0.descripcion')
                ->missing('ordenes.data.0.reportanteSnapshot')
                ->missing('ordenes.data.0.creadaPorUserId')
                ->missing('ordenes.data.0.estadoActualizadoPor'));
        $this->actingAs($viewer)->get(route('operaciones.show', [$building, $visibleOrder]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operacion/show')
                ->has('miembros', 0)
                ->has('proveedores', 0));
        $this->actingAs($viewer)->get(route('operaciones.edit', [$building, $visibleOrder]))->assertForbidden();
        $this->actingAs($viewer)->put(route('operaciones.update', [$building, $visibleOrder]), $this->updateData($visibleOrder))->assertForbidden();
        $this->actingAs($viewer)->patch(route('operaciones.transition', [$building, $visibleOrder]), ['estado' => 'en_revision'])->assertForbidden();
        $this->actingAs($viewer)->post(route('operaciones.assign', [$building, $visibleOrder]), [
            'tipo_responsable' => 'usuario',
            'responsable_id' => $operationsManager->id,
        ])->assertForbidden();
        $this->actingAs($viewer)->patch(route('operaciones.cancel', [$building, $visibleOrder]), ['motivo' => 'No autorizado'])->assertForbidden();
        $this->actingAs($viewer)->patch(route('operaciones.reopen', [$building, $visibleOrder]), ['motivo' => 'No autorizado'])->assertForbidden();
        $this->actingAs($viewer)->post(route('operaciones.actions.store', [$building, $visibleOrder]), ['descripcion' => 'No autorizado'])->assertForbidden();
        $this->actingAs($viewer)->post(route('operaciones.evidence.store', [$building, $visibleOrder]), [
            'archivo' => $this->pdf('no-autorizada.pdf'),
        ])->assertForbidden();

        $this->actingAs($otherAdministrator)->post(route('operaciones.store', $otherBuilding), $this->orderData())->assertSessionHasNoErrors();
        $otherOrder = OrdenOperativaEloquentModel::query()->where('edificio_id', $otherBuilding->id)->sole();
        $this->actingAs($operationsManager)->get(route('operaciones.show', [$otherBuilding, $otherOrder]))->assertNotFound();
        $this->actingAs($operationsManager)->put(route('operaciones.update', [$otherBuilding, $otherOrder]), $this->orderData())->assertForbidden();
        $this->assertDatabaseRejects(static fn () => DB::table('ordenes_operativas')->where('id', $otherOrder->id)->update([
            'estado' => 'en_revision',
            'estado_actualizado_por' => $operationsManager->id,
            'estado_actualizado_at' => now(),
        ]));

        $access = $this->app->make(AccesoEdificioRepositoryInterface::class);
        $this->assertTrue($access->hasPermission($operationsManager->id, $building->id, PermisoEdificio::OPERACIONES_REABRIR));
        $this->assertTrue($access->hasPermission($operationsManager->id, $building->id, PermisoEdificio::ESTRUCTURA_VER));
        $this->assertFalse($access->hasPermission($operationsManager->id, $building->id, PermisoEdificio::PROPIEDAD_VER));
        $this->assertTrue($access->hasPermission($viewer->id, $building->id, PermisoEdificio::OPERACIONES_VER));
        $this->assertFalse($access->hasPermission($viewer->id, $building->id, PermisoEdificio::OPERACIONES_GESTIONAR));
    }

    public function test_creation_uses_global_annual_number_and_validates_reporter_location_provider_and_contract_scope(): void
    {
        Carbon::setTestNow('2026-09-28 10:00:00');
        [$administrator, $building] = $this->fixture();
        $otherBuilding = $this->buildingFor($administrator);
        $resident = $this->residentFor($building, '0101000001', 'Ana', 'Residente');
        $alternateResident = $this->residentFor($building, '0101000003', 'Alicia', 'Alterna');
        $otherResident = $this->residentFor($otherBuilding, '0101000002', 'Otra', 'Persona');
        [$provider, $contract] = $this->supplierAndContract($administrator, $building, '1791000000001');
        [$otherProvider, $otherContract] = $this->supplierAndContract($administrator, $otherBuilding, '1791000000002');
        $tower = TorreEloquentModel::query()->where('edificio_id', $building->id)->sole();
        $otherTower = TorreEloquentModel::query()->where('edificio_id', $otherBuilding->id)->sole();

        $this->actingAs($administrator)->post(route('operaciones.store', $building), $this->orderData([
            'torre_id' => $tower->id,
            'ubicacion_detalle' => null,
            'reportante_residente_id' => $resident->id,
            'proveedor_id' => $provider->id,
            'contrato_id' => $contract->id,
        ]))->assertSessionHasNoErrors();
        $first = OrdenOperativaEloquentModel::query()->where('edificio_id', $building->id)->sole();
        $this->assertSame('OPR-2026-000001', $first->numero);
        $this->assertSame($resident->id, $first->reportante_snapshot['residenteId']);
        $this->assertSame('Ana Residente', $first->reportante_snapshot['nombre']);
        $this->assertSame($provider->id, $first->proveedor_id);
        $this->assertSame($contract->id, $first->contrato_id);

        $this->actingAs($administrator)->post(route('operaciones.store', $otherBuilding), $this->orderData([
            'titulo' => 'Segunda orden',
        ]))->assertSessionHasNoErrors();
        $second = OrdenOperativaEloquentModel::query()->where('edificio_id', $otherBuilding->id)->sole();
        $this->assertSame('OPR-2026-000002', $second->numero);

        $this->actingAs($administrator)->post(route('operaciones.store', $building), $this->orderData([
            'torre_id' => $otherTower->id,
            'ubicacion_detalle' => null,
        ]))->assertSessionHasErrors('torreId');
        $this->actingAs($administrator)->post(route('operaciones.store', $building), $this->orderData([
            'reportante_residente_id' => $otherResident->id,
        ]))->assertSessionHasErrors('reportanteResidenteId');
        $this->actingAs($administrator)->post(route('operaciones.store', $building), $this->orderData([
            'proveedor_id' => $otherProvider->id,
        ]))->assertSessionHasErrors('proveedorId');
        $this->actingAs($administrator)->post(route('operaciones.store', $building), $this->orderData([
            'proveedor_id' => $provider->id,
            'contrato_id' => $otherContract->id,
        ]))->assertSessionHasErrors('contratoId');
        $this->actingAs($administrator)->post(route('operaciones.store', $building), $this->orderData([
            'ubicacion_detalle' => null,
        ]))->assertSessionHasErrors('ubicacionDetalle');
        $this->actingAs($administrator)->post(route('operaciones.store', $building), $this->orderData([
            'torre_id' => $tower->id,
            'piso_id' => (string) Str::uuid(),
            'ubicacion_detalle' => null,
        ]))->assertSessionHasErrors('ubicacion');

        $resident->tercero()->update(['nombres' => 'Nombre cambiado']);
        $this->assertSame('Ana Residente', $first->fresh()->reportante_snapshot['nombre']);
        $this->assertDatabaseRejects(static fn () => DB::table('ordenes_operativas')->where('id', $first->id)->update([
            'reportante_snapshot' => json_encode(['nombre' => 'Manipulado'], JSON_THROW_ON_ERROR),
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('ordenes_operativas')->where('id', $first->id)->update([
            'reportante_residente_id' => $alternateResident->id,
            'reportante_snapshot' => 'null',
        ]));
    }

    public function test_route_options_are_scoped_minimal_and_historical_labels_remain_visible(): void
    {
        [$administrator, $building] = $this->fixture();
        $otherBuilding = $this->buildingFor($administrator);
        $manager = UserEloquentModel::factory()->create();
        $viewer = UserEloquentModel::factory()->create();
        $otherMember = UserEloquentModel::factory()->create();
        $this->addMember($building, $manager, RolEdificio::GESTOR_OPERACIONES, $administrator);
        $this->addMember($building, $viewer, RolEdificio::CONSULTA, $administrator);
        $this->addMember($otherBuilding, $manager, RolEdificio::GESTOR_OPERACIONES, $administrator);
        $this->addMember($otherBuilding, $otherMember, RolEdificio::CONSULTA, $administrator);
        $resident = $this->residentFor($building, '0103000001', 'Reporte', 'Local');
        $this->residentFor($otherBuilding, '0103000002', 'Reporte', 'Externo');
        [$provider, $contract] = $this->supplierAndContract($administrator, $building, '1793000000001');
        $this->supplierAndContract($administrator, $otherBuilding, '1793000000002');
        $tower = TorreEloquentModel::query()->where('edificio_id', $building->id)->sole();
        $order = $this->createOrder($administrator, $building, [
            'torre_id' => $tower->id,
            'ubicacion_detalle' => null,
            'reportante_residente_id' => $resident->id,
            'proveedor_id' => $provider->id,
            'contrato_id' => $contract->id,
        ]);
        $this->actingAs($administrator)->post(route('operaciones.assign', [$building, $order]), [
            'tipo_responsable' => 'usuario',
            'responsable_id' => $manager->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($manager)->get(route('operaciones.show', [$building, $order]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('miembros', fn ($items): bool => $items->every(fn ($item): bool => $item['edificioId'] === $building->id))
                ->where('proveedores', fn ($items): bool => $items->every(fn ($item): bool => $item['edificioId'] === $building->id))
                ->missing('proveedores.0.telefono')
                ->where('orden.bitacora.0.actorNombre', $administrator->name));
        $this->actingAs($manager)->get(route('operaciones.edit', [$building, $order]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('miembros')
                ->where('edificios', fn ($items): bool => $items->every(fn ($item): bool => $item['id'] === $building->id))
                ->where('residentes', fn ($items): bool => $items->every(fn ($item): bool => $item['edificioId'] === $building->id))
                ->where('proveedores', fn ($items): bool => $items->every(fn ($item): bool => $item['edificioId'] === $building->id))
                ->missing('residentes.0.telefono')
                ->missing('proveedores.0.contacto'));
        $this->actingAs($viewer)->get(route('operaciones.show', [$building, $order]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('orden.reportanteSnapshot.edificioId')
                ->missing('orden.reportanteSnapshot.residenteId')
                ->missing('orden.reportanteSnapshot.telefono')
                ->missing('orden.reportanteSnapshot.correo')
                ->missing('orden.responsableActual.responsableSnapshot')
                ->missing('orden.bitacora.0.actorUserId'));

        DB::table('torres')->where('id', $tower->id)->update(['estado' => 'inactivo']);
        DB::table('contratos_proveedor')->where('id', $contract->id)->update([
            'estado' => 'anulado',
            'anulado_at' => now(),
            'anulado_por' => $administrator->id,
            'motivo_anulacion' => 'Validación de lectura histórica',
        ]);
        DB::table('proveedor_edificio')->where('edificio_id', $building->id)->where('proveedor_id', $provider->id)->update(['estado' => 'inactivo']);
        $detail = app(OrdenOperativaRepositoryInterface::class)->get($administrator->id, $building->id, $order->id);
        $this->assertNotNull($detail['ubicacion']['etiqueta']);
        $this->assertSame($provider->id, $detail['proveedor']['proveedorId']);
        $this->assertSame($contract->id, $detail['contrato']['id']);
        $this->actingAs($administrator)->get(route('operaciones.edit', [$building, $order]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('torres', 0)
                ->has('proveedores', 0)
                ->has('contratos', 0));
        $this->actingAs($administrator)->put(route('operaciones.update', [$building, $order]), $this->updateData($order, [
            'titulo' => 'Orden histórica actualizada',
            'torre_id' => $tower->id,
            'ubicacion_detalle' => null,
            'reportante_residente_id' => $resident->id,
            'proveedor_id' => $provider->id,
            'contrato_id' => $contract->id,
        ]))->assertSessionHasNoErrors();
        $this->actingAs($administrator)->post(route('operaciones.store', $building), $this->orderData([
            'torre_id' => $tower->id,
            'ubicacion_detalle' => null,
        ]))->assertSessionHasErrors('torreId');
        $this->actingAs($administrator)->post(route('operaciones.store', $building), $this->orderData([
            'proveedor_id' => $provider->id,
        ]))->assertSessionHasErrors('proveedorId');
    }

    public function test_state_graph_requires_active_responsibility_and_reassignment_preserves_intervals(): void
    {
        [$administrator, $building] = $this->fixture();
        $firstResponsible = UserEloquentModel::factory()->create();
        $secondResponsible = UserEloquentModel::factory()->create();
        $this->addMember($building, $firstResponsible, RolEdificio::CONSULTA, $administrator);
        $this->addMember($building, $secondResponsible, RolEdificio::CONSULTA, $administrator);
        $order = $this->createOrder($administrator, $building);

        $this->actingAs($administrator)->patch(route('operaciones.transition', [$building, $order]), ['estado' => 'en_progreso'])
            ->assertSessionHasErrors('estado');
        $this->actingAs($administrator)->patch(route('operaciones.transition', [$building, $order]), ['estado' => 'en_revision'])
            ->assertSessionHasNoErrors();
        $this->actingAs($administrator)->patch(route('operaciones.transition', [$building, $order]), ['estado' => 'en_progreso'])
            ->assertSessionHasErrors('responsable');

        $this->actingAs($administrator)->post(route('operaciones.assign', [$building, $order]), [
            'tipo_responsable' => 'usuario',
            'responsable_id' => $firstResponsible->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($administrator)->patch(route('operaciones.transition', [$building, $order]), ['estado' => 'en_progreso'])
            ->assertSessionHasNoErrors();
        $this->assertSame(EstadoOrdenOperativa::EN_PROGRESO, $order->refresh()->estado);

        DB::table('edificio_usuario')->where('edificio_id', $building->id)->where('user_id', $firstResponsible->id)->update([
            'revoked_at' => now(),
            'revocado_por_user_id' => $administrator->id,
            'updated_at' => now(),
        ]);
        $this->actingAs($administrator)->patch(route('operaciones.transition', [$building, $order]), ['estado' => 'resuelta'])
            ->assertSessionHasErrors('responsable');
        $this->actingAs($administrator)->post(route('operaciones.assign', [$building, $order]), [
            'tipo_responsable' => 'usuario',
            'responsable_id' => $secondResponsible->id,
        ])->assertSessionHasNoErrors();

        $assignments = AsignacionOrdenOperativaEloquentModel::query()->where('orden_operativa_id', $order->id)->orderBy('fecha_inicio')->get();
        $this->assertCount(2, $assignments);
        $this->assertNotNull($assignments[0]->fecha_fin);
        $this->assertSame($administrator->id, $assignments[0]->finalizado_por_user_id);
        $this->assertNull($assignments[1]->fecha_fin);
        $this->assertSame($secondResponsible->id, $assignments[1]->responsable_user_id);
        $this->assertDatabaseRejects(static fn () => DB::table('asignaciones_orden_operativa')->insert([
            'id' => (string) Str::uuid(),
            'edificio_id' => $building->id,
            'orden_operativa_id' => $order->id,
            'tipo_responsable' => 'usuario',
            'responsable_user_id' => $secondResponsible->id,
            'responsable_proveedor_id' => null,
            'responsable_snapshot' => json_encode(['usuarioId' => $secondResponsible->id], JSON_THROW_ON_ERROR),
            'asignado_por_user_id' => $administrator->id,
            'finalizado_por_user_id' => null,
            'fecha_inicio' => now(),
            'fecha_fin' => null,
        ]));

        $invalidSnapshot = $this->createOrder($administrator, $building, ['titulo' => 'Snapshot protegido']);
        $this->assertDatabaseRejects(static fn () => DB::table('asignaciones_orden_operativa')->insert([
            'id' => (string) Str::uuid(),
            'edificio_id' => $building->id,
            'orden_operativa_id' => $invalidSnapshot->id,
            'tipo_responsable' => 'usuario',
            'responsable_user_id' => $secondResponsible->id,
            'responsable_proveedor_id' => null,
            'responsable_snapshot' => 'null',
            'asignado_por_user_id' => $administrator->id,
            'finalizado_por_user_id' => null,
            'fecha_inicio' => now(),
            'fecha_fin' => null,
        ]));

        $overlap = $this->createOrder($administrator, $building, ['titulo' => 'Intervalos protegidos']);
        $firstStart = Carbon::now()->subHours(2);
        $firstEnd = Carbon::now()->subHour();
        $historicalAssignmentId = (string) Str::uuid();
        DB::table('asignaciones_orden_operativa')->insert([
            'id' => $historicalAssignmentId,
            'edificio_id' => $building->id,
            'orden_operativa_id' => $overlap->id,
            'tipo_responsable' => 'usuario',
            'responsable_user_id' => $secondResponsible->id,
            'responsable_proveedor_id' => null,
            'responsable_snapshot' => json_encode(['nombre' => 'Responsable histórico'], JSON_THROW_ON_ERROR),
            'asignado_por_user_id' => $administrator->id,
            'finalizado_por_user_id' => null,
            'fecha_inicio' => $firstStart,
            'fecha_fin' => null,
        ]);
        DB::table('asignaciones_orden_operativa')->where('id', $historicalAssignmentId)->update([
            'finalizado_por_user_id' => $administrator->id,
            'fecha_fin' => $firstEnd,
        ]);
        $this->assertDatabaseRejects(static fn () => DB::table('asignaciones_orden_operativa')->insert([
            'id' => (string) Str::uuid(),
            'edificio_id' => $building->id,
            'orden_operativa_id' => $overlap->id,
            'tipo_responsable' => 'usuario',
            'responsable_user_id' => $secondResponsible->id,
            'responsable_proveedor_id' => null,
            'responsable_snapshot' => json_encode(['nombre' => 'Responsable solapado'], JSON_THROW_ON_ERROR),
            'asignado_por_user_id' => $administrator->id,
            'finalizado_por_user_id' => null,
            'fecha_inicio' => $firstStart->addMinutes(30),
            'fecha_fin' => null,
        ]));

        $staleTransition = $this->createOrder($administrator, $building, ['titulo' => 'Transición protegida']);
        $this->actingAs($administrator)->patch(route('operaciones.transition', [$building, $staleTransition]), ['estado' => 'en_revision'])
            ->assertSessionHasNoErrors();
        $this->actingAs($administrator)->post(route('operaciones.assign', [$building, $staleTransition]), [
            'tipo_responsable' => 'usuario',
            'responsable_id' => $secondResponsible->id,
        ])->assertSessionHasNoErrors();
        $stateUpdatedAt = $staleTransition->refresh()->estado_actualizado_at;
        $this->assertDatabaseRejects(static fn () => DB::table('ordenes_operativas')->where('id', $staleTransition->id)->update([
            'estado' => 'en_progreso',
            'estado_actualizado_por' => $administrator->id,
            'estado_actualizado_at' => $stateUpdatedAt,
            'motivo_estado' => null,
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('ordenes_operativas')->where('id', $staleTransition->id)->update([
            'estado' => 'en_progreso',
            'estado_actualizado_por' => $administrator->id,
            'estado_actualizado_at' => $stateUpdatedAt?->copy()->addSecond(),
            'motivo_estado' => 'Una transición normal no admite motivo',
        ]));

        $this->actingAs($administrator)->patch(route('operaciones.transition', [$building, $order]), ['estado' => 'resuelta'])->assertSessionHasNoErrors();
        $this->actingAs($administrator)->patch(route('operaciones.reopen', [$building, $order]), ['motivo' => 'La falla reapareció'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoOrdenOperativa::EN_PROGRESO, $order->refresh()->estado);
        $this->actingAs($administrator)->patch(route('operaciones.transition', [$building, $order]), ['estado' => 'resuelta'])->assertSessionHasNoErrors();
        $this->actingAs($administrator)->patch(route('operaciones.transition', [$building, $order]), ['estado' => 'cerrada'])->assertSessionHasNoErrors();
        $this->actingAs($administrator)->patch(route('operaciones.reopen', [$building, $order]), ['motivo' => 'Nueva verificación'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoOrdenOperativa::EN_PROGRESO, $order->refresh()->estado);

        $cancelled = $this->createOrder($administrator, $building, ['titulo' => 'Orden cancelable']);
        $this->actingAs($administrator)->post(route('operaciones.assign', [$building, $cancelled]), [
            'tipo_responsable' => 'usuario',
            'responsable_id' => $secondResponsible->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($administrator)->patch(route('operaciones.cancel', [$building, $cancelled]), ['motivo' => 'Reporte duplicado'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoOrdenOperativa::CANCELADA, $cancelled->refresh()->estado);
        $this->assertNotNull(AsignacionOrdenOperativaEloquentModel::query()->where('orden_operativa_id', $cancelled->id)->sole()->fecha_fin);
        $this->actingAs($administrator)->patch(route('operaciones.reopen', [$building, $cancelled]), ['motivo' => 'Intento inválido'])->assertSessionHasErrors('estado');

        $guardedCancellation = $this->createOrder($administrator, $building, ['titulo' => 'Cancelación protegida']);
        $this->actingAs($administrator)->post(route('operaciones.assign', [$building, $guardedCancellation]), [
            'tipo_responsable' => 'usuario',
            'responsable_id' => $secondResponsible->id,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseRejects(static fn () => DB::table('ordenes_operativas')->where('id', $guardedCancellation->id)->update([
            'estado' => 'cancelada',
            'estado_actualizado_por' => $administrator->id,
            'estado_actualizado_at' => now(),
            'motivo_estado' => 'No debe conservar responsable',
        ]));

        $invalid = $this->createOrder($administrator, $building, ['titulo' => 'Salto inválido']);
        $this->assertDatabaseRejects(static fn () => DB::table('ordenes_operativas')->where('id', $invalid->id)->update([
            'estado' => 'resuelta',
            'estado_actualizado_por' => $administrator->id,
            'estado_actualizado_at' => now(),
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('asignaciones_orden_operativa')->where('id', $assignments[0]->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('ordenes_operativas')->where('id', $order->id)->delete());
    }

    public function test_provider_responsibility_sets_association_rejects_mismatch_and_does_not_mutate_source_contexts(): void
    {
        [$administrator, $building] = $this->fixture();
        [$provider, $contract] = $this->supplierAndContract($administrator, $building, '1792000000001');
        [$otherProvider] = $this->supplierAndContract($administrator, $building, '1792000000002');
        $resident = $this->residentFor($building, '0102000001', 'Rita', 'Reporte');
        $order = $this->createOrder($administrator, $building, ['reportante_residente_id' => $resident->id]);
        $trackedTables = ['terceros', 'residentes', 'residente_edificio', 'proveedores', 'proveedor_edificio', 'contratos_proveedor', 'gastos', 'cuentas_por_pagar', 'desembolsos', 'cargos', 'pagos', 'movimientos_tesoreria'];
        $counts = collect($trackedTables)->mapWithKeys(static fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $supplierUpdatedAt = DB::table('proveedor_edificio')->where('edificio_id', $building->id)->where('proveedor_id', $provider->id)->value('updated_at');

        $this->actingAs($administrator)->post(route('operaciones.assign', [$building, $order]), [
            'tipo_responsable' => 'proveedor',
            'responsable_id' => $provider->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($provider->id, $order->refresh()->proveedor_id);
        $this->assertNull($order->contrato_id);
        $this->actingAs($administrator)->post(route('operaciones.assign', [$building, $order]), [
            'tipo_responsable' => 'proveedor',
            'responsable_id' => $otherProvider->id,
        ])->assertSessionHasErrors('responsableId');

        $this->actingAs($administrator)->put(route('operaciones.update', [$building, $order]), $this->updateData($order, [
            'reportante_residente_id' => $resident->id,
            'proveedor_id' => $provider->id,
            'contrato_id' => $contract->id,
        ]))->assertSessionHasNoErrors();
        $internal = UserEloquentModel::factory()->create();
        $this->addMember($building, $internal, RolEdificio::CONSULTA, $administrator);
        $this->actingAs($administrator)->post(route('operaciones.assign', [$building, $order]), [
            'tipo_responsable' => 'usuario',
            'responsable_id' => $internal->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($provider->id, $order->refresh()->proveedor_id);
        $this->assertSame($contract->id, $order->contrato_id);

        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), 'Operaciones alteró '.$table);
        }
        $this->assertSame($supplierUpdatedAt, DB::table('proveedor_edificio')->where('edificio_id', $building->id)->where('proveedor_id', $provider->id)->value('updated_at'));
        $this->assertDatabaseCount('gastos', 0);
        $this->assertDatabaseCount('cuentas_por_pagar', 0);
        $this->assertDatabaseCount('desembolsos', 0);
        $this->assertDatabaseCount('movimientos_tesoreria', 0);
    }

    public function test_updates_actions_and_private_evidence_are_audited_immutable_and_blocked_after_close(): void
    {
        Storage::fake('evidence');
        [$administrator, $building] = $this->fixture();
        $viewer = UserEloquentModel::factory()->create();
        $responsible = UserEloquentModel::factory()->create();
        $this->addMember($building, $viewer, RolEdificio::CONSULTA, $administrator);
        $this->addMember($building, $responsible, RolEdificio::CONSULTA, $administrator);
        Carbon::setTestNow('2026-09-28 10:00:00');
        $order = $this->createOrder($administrator, $building);

        $stalePayload = $this->updateData($order, [
            'titulo' => 'Ascensor detenido en torre principal',
            'prioridad' => 'critica',
            'fecha_objetivo' => '2026-10-01',
        ]);
        $this->actingAs($administrator)->put(route('operaciones.update', [$building, $order]), $stalePayload)->assertSessionHasNoErrors();
        $this->assertNotSame($stalePayload['updated_at'], $order->refresh()->updated_at?->toIso8601String());
        $this->actingAs($administrator)->put(route('operaciones.update', [$building, $order]), [
            ...$stalePayload,
            'titulo' => 'Edición obsoleta',
        ])->assertSessionHasErrors('updatedAt');
        $this->assertSame('Ascensor detenido en torre principal', $order->refresh()->titulo);
        $this->actingAs($administrator)->put(route('operaciones.update', [$building, $order]), [
            ...$this->updateData($order),
            'tipo' => 'solicitud',
        ])->assertSessionHasErrors('tipo');
        $this->assertSame('incidencia', $order->refresh()->tipo->value);
        $this->assertDatabaseRejects(static fn () => DB::table('ordenes_operativas')->where('id', $order->id)->update([
            'tipo' => 'solicitud',
        ]));
        $this->actingAs($administrator)->post(route('operaciones.actions.store', [$building, $order]), [
            'descripcion' => 'Se realizó diagnóstico manual.',
        ])->assertSessionHasNoErrors();
        $this->actingAs($administrator)->post(route('operaciones.assign', [$building, $order]), [
            'tipo_responsable' => 'usuario',
            'responsable_id' => $responsible->id,
        ])->assertSessionHasNoErrors();
        foreach (['en_revision', 'en_progreso', 'resuelta'] as $state) {
            $this->actingAs($administrator)->patch(route('operaciones.transition', [$building, $order]), ['estado' => $state])->assertSessionHasNoErrors();
        }

        $this->actingAs($administrator)->post(route('operaciones.evidence.store', [$building, $order]), [
            'archivo' => UploadedFile::fake()->createWithContent('falso.pdf', 'MZ ejecutable'),
        ])->assertSessionHasErrors('archivo');
        $this->assertDatabaseCount('evidencias_orden_operativa', 0);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER operaciones_test_evidencia_failure
            BEFORE INSERT ON evidencias_orden_operativa
            BEGIN
                SELECT RAISE(ABORT, 'Fallo de persistencia simulado');
            END;
            SQL);
        try {
            app(OrdenOperativaRepositoryInterface::class)->storeEvidence(
                $administrator->id,
                $building->id,
                $order->id,
                $this->pdf('fallo-db.pdf'),
                null,
            );
            $this->fail('La persistencia simulada de evidencia debió fallar.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Fallo de persistencia simulado', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS operaciones_test_evidencia_failure');
        }
        $this->assertSame([], Storage::disk('evidence')->allFiles('operaciones/'.$building->id.'/'.$order->id));

        $this->actingAs($administrator)->post(route('operaciones.evidence.store', [$building, $order]), [
            'archivo' => $this->pdf('diagnostico.pdf'),
            'descripcion' => 'Informe técnico',
        ])->assertSessionHasNoErrors();
        $evidence = EvidenciaOrdenOperativaEloquentModel::query()->sole();
        Storage::disk('evidence')->assertExists($evidence->ruta_privada);
        $this->actingAs($viewer)->get(route('operaciones.evidence.download', [$building, $order, $evidence]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertStreamedContent($this->pdfContents());
        $this->actingAs($administrator)->post(route('operaciones.evidence.store', [$building, $order]), [
            'archivo' => UploadedFile::fake()->createWithContent(
                'tablero.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
            ),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('evidencias_orden_operativa', 2);
        $this->actingAs($viewer)->get(route('operaciones.show', [$building, $order]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('orden.evidencias.0.sha256')
                ->missing('orden.evidencias.0.rutaPrivada')
                ->missing('orden.evidencias.0.subidoPorUserId')
                ->missing('orden.asignaciones.0.responsableSnapshot')
                ->missing('orden.bitacora.0.actorUserId'));

        $types = BitacoraOrdenOperativaEloquentModel::query()->where('orden_operativa_id', $order->id)->pluck('tipo')->map(static fn ($type) => is_string($type) ? $type : $type->value)->all();
        foreach (['creacion', 'cambio_datos', 'actuacion_manual', 'asignacion', 'cambio_estado', 'evidencia'] as $type) {
            $this->assertContains($type, $types);
        }
        $this->assertDatabaseRejects(static fn () => DB::table('evidencias_orden_operativa')->where('id', $evidence->id)->update(['descripcion' => 'Alterada']));
        $this->assertDatabaseRejects(static fn () => DB::table('evidencias_orden_operativa')->where('id', $evidence->id)->delete());
        $log = BitacoraOrdenOperativaEloquentModel::query()->where('orden_operativa_id', $order->id)->firstOrFail();
        $this->assertDatabaseRejects(static fn () => DB::table('bitacora_orden_operativa')->where('id', $log->id)->delete());

        Storage::disk('evidence')->put($evidence->ruta_privada, 'contenido alterado');
        $this->actingAs($viewer)->get(route('operaciones.evidence.download', [$building, $order, $evidence]))->assertStatus(409);
        Storage::disk('evidence')->put($evidence->ruta_privada, $this->pdfContents());

        $this->actingAs($administrator)->patch(route('operaciones.transition', [$building, $order]), ['estado' => 'cerrada'])->assertSessionHasNoErrors();
        $this->actingAs($administrator)->get(route('operaciones.edit', [$building, $order]))
            ->assertRedirect(route('operaciones.show', [$building, $order]));
        $this->actingAs($administrator)->put(route('operaciones.update', [$building, $order]), $this->updateData($order))->assertSessionHasErrors('estado');
        $this->actingAs($administrator)->post(route('operaciones.actions.store', [$building, $order]), ['descripcion' => 'No debe entrar'])->assertSessionHasErrors('estado');
        $this->actingAs($administrator)->post(route('operaciones.evidence.store', [$building, $order]), [
            'archivo' => $this->pdf('cerrada.pdf'),
        ])->assertSessionHasErrors('estado');
        $this->actingAs($administrator)->post(route('operaciones.evidence.store', [$building, $order]), [
            'archivo' => UploadedFile::fake()->createWithContent('falso.pdf', 'MZ ejecutable'),
        ])->assertSessionHasErrors('estado');
        $this->assertDatabaseRejects(static fn () => DB::table('evidencias_orden_operativa')->insert([
            'id' => (string) Str::uuid(),
            'edificio_id' => $building->id,
            'orden_operativa_id' => $order->id,
            'nombre_original' => 'directa.pdf',
            'mime_type' => 'application/pdf',
            'tamano_bytes' => 1,
            'sha256' => str_repeat('a', 64),
            'ruta_privada' => 'operaciones/directa-'.Str::uuid().'.pdf',
            'descripcion' => null,
            'subido_por_user_id' => $administrator->id,
            'created_at' => now(),
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('bitacora_orden_operativa')->insert([
            'id' => (string) Str::uuid(),
            'edificio_id' => $building->id,
            'orden_operativa_id' => $order->id,
            'tipo' => 'actuacion_manual',
            'actor_user_id' => $administrator->id,
            'detalle' => json_encode(['descripcion' => 'No debe entrar'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]));
        Storage::disk('evidence')->assertExists($evidence->ruta_privada);

        $capacityOrder = $this->createOrder($administrator, $building, ['titulo' => 'Capacidad de evidencias']);
        foreach (range(1, 50) as $index) {
            DB::table('evidencias_orden_operativa')->insert([
                'id' => (string) Str::uuid(),
                'edificio_id' => $building->id,
                'orden_operativa_id' => $capacityOrder->id,
                'nombre_original' => 'historica-'.$index.'.pdf',
                'mime_type' => 'application/pdf',
                'tamano_bytes' => 10 * 1024 * 1024,
                'sha256' => hash('sha256', 'historica-'.$index),
                'ruta_privada' => 'operaciones/'.$building->id.'/'.$capacityOrder->id.'/historica-'.$index.'.pdf',
                'descripcion' => null,
                'subido_por_user_id' => $administrator->id,
                'created_at' => now(),
            ]);
        }
        try {
            app(OrdenOperativaRepositoryInterface::class)->storeEvidence(
                $administrator->id,
                $building->id,
                $capacityOrder->id,
                $this->pdf('sin-capacidad.pdf'),
                null,
            );
            $this->fail('La orden no debe superar 500 MB de evidencias acumuladas.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('archivo', $exception->errors());
        }
        $this->assertSame([], Storage::disk('evidence')->allFiles('operaciones/'.$building->id.'/'.$capacityOrder->id));
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel} */
    private function fixture(): array
    {
        $administrator = UserEloquentModel::factory()->create();

        return [$administrator, $this->buildingFor($administrator)];
    }

    private function buildingFor(UserEloquentModel $administrator): EdificioEloquentModel
    {
        $number = DB::table('edificios')->count() + 1;
        $created = app(CreateEdificioAction::class)->execute([
            'nombre' => 'Edificio Operativo '.$number,
            'ruc' => str_pad((string) $number, 13, '0', STR_PAD_LEFT),
            'direccion' => 'Dirección '.$number,
            'ciudad' => 'Quito',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $administrator->id);

        return EdificioEloquentModel::query()->findOrFail($created->id());
    }

    private function createOrder(UserEloquentModel $actor, EdificioEloquentModel $building, array $overrides = []): OrdenOperativaEloquentModel
    {
        $before = OrdenOperativaEloquentModel::query()->pluck('id');
        $this->actingAs($actor)->post(route('operaciones.store', $building), $this->orderData($overrides))->assertSessionHasNoErrors();

        return OrdenOperativaEloquentModel::query()->whereNotIn('id', $before)->sole();
    }

    /** @return array<string, mixed> */
    private function orderData(array $overrides = []): array
    {
        return array_merge([
            'tipo' => 'incidencia',
            'titulo' => 'Ascensor detenido',
            'descripcion' => 'El ascensor no responde al llamado.',
            'prioridad' => 'alta',
            'fecha_objetivo' => null,
            'torre_id' => null,
            'piso_id' => null,
            'departamento_id' => null,
            'parqueadero_id' => null,
            'bodega_id' => null,
            'ubicacion_detalle' => 'Vestíbulo principal',
            'reportante_residente_id' => null,
            'proveedor_id' => null,
            'contrato_id' => null,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function updateData(OrdenOperativaEloquentModel $order, array $overrides = []): array
    {
        $data = $this->orderData($overrides);
        unset($data['tipo']);
        $data['updated_at'] = $order->fresh()->updated_at?->toIso8601String();

        return $data;
    }

    private function residentFor(EdificioEloquentModel $building, string $identification, string $names, string $lastNames): ResidenteEloquentModel
    {
        $identity = TerceroEloquentModel::query()->create([
            'tipo_persona' => 'persona_natural',
            'nombres' => $names,
            'apellidos' => $lastNames,
            'razon_social' => null,
            'tipo_identificacion' => 'cedula',
            'identificacion' => $identification,
            'telefono' => '022000000',
            'celular' => null,
            'correo' => mb_strtolower($names).'@example.test',
            'direccion' => null,
        ]);
        $resident = ResidenteEloquentModel::query()->create([
            'tercero_id' => $identity->id,
            'estado' => 'activo',
            'observaciones' => null,
        ]);
        DB::table('residente_edificio')->insert([
            'residente_id' => $resident->id,
            'edificio_id' => $building->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $resident;
    }

    /** @return array{ProveedorEloquentModel, ContratoProveedorEloquentModel} */
    private function supplierAndContract(UserEloquentModel $actor, EdificioEloquentModel $building, string $identification): array
    {
        $this->actingAs($actor)->post(route('proveedores.store', $building), [
            'tipo_persona' => 'persona_juridica',
            'nombres' => null,
            'apellidos' => null,
            'razon_social' => 'Proveedor '.$identification,
            'tipo_identificacion' => 'ruc',
            'identificacion' => $identification,
            'telefono' => '022111111',
            'celular' => null,
            'correo' => $identification.'@example.test',
            'direccion' => 'Dirección proveedor',
            'nombre_comercial' => 'Proveedor operativo',
            'contacto' => 'Contacto',
            'telefono_comercial' => null,
            'correo_comercial' => null,
            'direccion_comercial' => null,
            'dias_credito' => 30,
            'observaciones' => null,
        ])->assertSessionHasNoErrors();
        $provider = ProveedorEloquentModel::query()->whereHas('tercero', static fn ($query) => $query->where('identificacion', $identification))->sole();
        $reference = 'CONT-'.substr($identification, -4);
        $this->actingAs($actor)->post(route('contratos-proveedor.store', $building), [
            'proveedor_id' => $provider->id,
            'referencia' => $reference,
            'objeto' => 'Mantenimiento correctivo',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2027-12-31',
            'monto_total' => '1000.0000',
            'observaciones' => null,
        ])->assertSessionHasNoErrors();
        $contract = ContratoProveedorEloquentModel::query()->where('edificio_id', $building->id)->where('referencia', $reference)->sole();
        $this->actingAs($actor)->patch(route('contratos-proveedor.register', [$building, $contract]))->assertSessionHasNoErrors();

        return [$provider, $contract->refresh()];
    }

    private function addMember(EdificioEloquentModel $building, UserEloquentModel $member, RolEdificio $role, UserEloquentModel $actor): void
    {
        $building->usuarios()->attach($member->id, ['creado_por_user_id' => $actor->id]);
        DB::table('edificio_usuario_roles')->insert([
            'edificio_id' => $building->id,
            'user_id' => $member->id,
            'rol_codigo' => $role->value,
            'asignado_por_user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pdfContents());
    }

    private function pdfContents(): string
    {
        $header = "%PDF-1.4\n";
        $object = "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $xrefOffset = strlen($header.$object);

        return $header.$object
            ."xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            ."trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('La base de datos permitió una mutación operativa protegida.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
