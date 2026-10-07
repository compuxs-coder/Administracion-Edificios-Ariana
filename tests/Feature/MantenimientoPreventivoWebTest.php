<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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
use Src\Operaciones\Application\Actions\GeneratePreventiveMaintenanceOrdersAction;
use Src\Operaciones\Domain\Enums\EstadoOcurrenciaMantenimientoPreventivo;
use Src\Operaciones\Domain\Enums\EstadoPlanMantenimientoPreventivo;
use Src\Operaciones\Domain\Enums\OrigenOrdenOperativa;
use Src\Operaciones\Domain\Enums\TipoActorOperativo;
use Src\Operaciones\Domain\Enums\TipoOrdenOperativa;
use Src\Operaciones\Domain\Enums\UnidadRecurrenciaMantenimiento;
use Src\Operaciones\Domain\Contracts\ProveedoresOperacionesReadInterface;
use Src\Operaciones\Infrastructure\Models\BitacoraOrdenOperativaEloquentModel;
use Src\Operaciones\Infrastructure\Models\BitacoraPlanMantenimientoEloquentModel;
use Src\Operaciones\Infrastructure\Models\OcurrenciaMantenimientoPreventivoEloquentModel;
use Src\Operaciones\Infrastructure\Models\OrdenOperativaEloquentModel;
use Src\Operaciones\Infrastructure\Models\PlanMantenimientoPreventivoEloquentModel;
use Tests\TestCase;

final class MantenimientoPreventivoWebTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_routes_enforce_exact_role_matrix_authentication_and_building_isolation(): void
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

        $this->get(route('mantenimiento-preventivo.index'))->assertRedirect(route('login'));
        $this->get(route('mantenimiento-preventivo.create'))->assertRedirect(route('login'));
        $this->post(route('mantenimiento-preventivo.store', $building), $this->planData())->assertRedirect(route('login'));
        foreach (['mantenimiento-preventivo.destroy', 'mantenimiento-preventivo.occurrences.destroy'] as $routeName) {
            $this->assertFalse(Route::has($routeName));
        }

        $this->actingAs($operationsManager)->get(route('mantenimiento-preventivo.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('MantenimientoPreventivo/index')->has('edificios', 1)->where('edificios.0.id', $building->id));
        $this->actingAs($operationsManager)->get(route('mantenimiento-preventivo.create'))->assertOk();
        $this->actingAs($viewer)->get(route('mantenimiento-preventivo.index'))->assertOk();
        $this->actingAs($viewer)->get(route('mantenimiento-preventivo.create'))->assertForbidden();
        $this->actingAs($propertyManager)->get(route('mantenimiento-preventivo.index'))->assertForbidden();
        $this->actingAs($financeManager)->get(route('mantenimiento-preventivo.index'))->assertForbidden();

        $plan = $this->createPlan($administrator, $building);
        $this->actingAs($viewer)->get(route('mantenimiento-preventivo.show', [$building, $plan]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('MantenimientoPreventivo/show')->where('plan.codigo', 'ASCENSOR-T1'));
        $this->actingAs($viewer)->put(route('mantenimiento-preventivo.update', [$building, $plan]), $this->updateData($plan))->assertForbidden();
        $this->actingAs($otherAdministrator)->get(route('mantenimiento-preventivo.show', [$building, $plan]))->assertNotFound();
        $this->actingAs($administrator)->get(route('mantenimiento-preventivo.show', [$otherBuilding, $plan]))->assertNotFound();

        $access = app(AccesoEdificioRepositoryInterface::class);
        foreach ([PermisoEdificio::MANTENIMIENTO_PREVENTIVO_VER, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_GESTIONAR, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_PROGRAMAR] as $permission) {
            $this->assertTrue($access->hasPermission($operationsManager->id, $building->id, $permission));
        }
        $this->assertTrue($access->hasPermission($viewer->id, $building->id, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_VER));
        $this->assertFalse($access->hasPermission($viewer->id, $building->id, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_GESTIONAR));
        $this->assertFalse($access->hasPermission($propertyManager->id, $building->id, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_VER));
        $this->assertFalse($access->hasPermission($financeManager->id, $building->id, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_VER));
    }

    public function test_plan_is_normalized_unique_editable_only_while_inactive_and_activation_requires_new_future_date(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        [$administrator, $building] = $this->fixture();
        $otherBuilding = $this->buildingFor($administrator);
        $otherTower = TorreEloquentModel::query()->where('edificio_id', $otherBuilding->id)->sole();

        $this->actingAs($administrator)->post(route('mantenimiento-preventivo.store', $building), $this->planData(['codigo' => ' ascensor-t1 ']))->assertSessionHasNoErrors();
        $plan = PlanMantenimientoPreventivoEloquentModel::query()->sole();
        $this->assertSame('ASCENSOR-T1', $plan->codigo);
        $this->assertSame(EstadoPlanMantenimientoPreventivo::INACTIVO, $plan->estado);
        $this->assertNull($plan->proxima_fecha_programada);
        $this->assertDatabaseCount('bitacora_plan_mantenimiento', 1);
        $this->assertDatabaseRejects(static fn () => DB::table('planes_mantenimiento_preventivo')->insert([
            'id' => fake()->uuid(), 'edificio_id' => $building->id, 'codigo' => 'ACTIVO-DIRECTO',
            'titulo' => 'Plan activo inválido', 'descripcion' => 'No debe insertarse activo.', 'prioridad' => 'media',
            'unidad_recurrencia' => 'mensual', 'intervalo_recurrencia' => 1, 'dias_anticipacion' => 0,
            'estado' => 'activo', 'fecha_ancla' => '2026-11-01', 'secuencia_siguiente' => 0,
            'proxima_fecha_programada' => '2026-11-01', 'ubicacion_detalle' => 'Área técnica',
            'creado_por_user_id' => $administrator->id, 'actualizado_por_user_id' => $administrator->id,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->actingAs($administrator)->post(route('mantenimiento-preventivo.store', $building), $this->planData())->assertSessionHasErrors('codigo');
        $this->actingAs($administrator)->post(route('mantenimiento-preventivo.store', $building), $this->planData([
            'codigo' => 'OTRO', 'torre_id' => $otherTower->id, 'ubicacion_detalle' => null,
        ]))->assertSessionHasErrors('torreId');
        $this->actingAs($administrator)->post(route('operaciones.store', $building), [
            'tipo' => 'mantenimiento_preventivo', 'titulo' => 'Intento manual', 'descripcion' => 'No permitido', 'prioridad' => 'media', 'ubicacion_detalle' => 'Prueba',
        ])->assertSessionHasErrors('tipo');

        $this->actingAs($administrator)->put(route('mantenimiento-preventivo.update', [$building, $plan]), $this->updateData($plan, ['titulo' => 'Ascensor principal actualizado']))->assertSessionHasNoErrors();
        $this->assertSame('Ascensor principal actualizado', $plan->refresh()->titulo);
        $this->actingAs($administrator)->patch(route('mantenimiento-preventivo.status', [$building, $plan]), [
            'estado' => 'activo', 'proxima_fecha_programada' => '2026-10-04',
        ])->assertSessionHasErrors('proximaFechaProgramada');
        $this->actingAs($administrator)->patch(route('mantenimiento-preventivo.status', [$building, $plan]), [
            'estado' => 'activo', 'proxima_fecha_programada' => '2026-10-31',
        ])->assertSessionHasNoErrors();
        $plan->refresh();
        $this->assertSame(EstadoPlanMantenimientoPreventivo::ACTIVO, $plan->estado);
        $this->assertSame('2026-10-31', $plan->fecha_ancla?->format('Y-m-d'));
        $this->assertSame('2026-10-31', $plan->proxima_fecha_programada?->format('Y-m-d'));
        $this->actingAs($administrator)->get(route('mantenimiento-preventivo.edit', [$building, $plan]))->assertRedirect(route('mantenimiento-preventivo.show', [$building, $plan]));
        $this->actingAs($administrator)->put(route('mantenimiento-preventivo.update', [$building, $plan]), $this->updateData($plan))->assertSessionHasErrors('estado');

        $this->actingAs($administrator)->patch(route('mantenimiento-preventivo.status', [$building, $plan]), ['estado' => 'inactivo'])->assertSessionHasNoErrors();
        $this->assertNull($plan->refresh()->proxima_fecha_programada);
        $this->actingAs($administrator)->patch(route('mantenimiento-preventivo.status', [$building, $plan]), ['estado' => 'activo'])->assertSessionHasErrors('proximaFechaProgramada');
    }

    public function test_scheduler_generates_system_orders_idempotently_preserves_month_anchor_and_recovers_backlog_in_limited_batches(): void
    {
        Carbon::setTestNow('2028-01-30 10:00:00');
        [$administrator, $building] = $this->fixture();
        $plan = $this->createPlan($administrator, $building, ['dias_anticipacion' => 0]);
        $this->activate($administrator, $building, $plan, '2028-01-31');
        $generate = app(GeneratePreventiveMaintenanceOrdersAction::class);
        $financialCounts = $this->financialCounts();

        $first = $generate->execute(CarbonImmutable::parse('2028-01-31'), null, 100);
        $this->assertSame(1, $first['generadas']);
        $order = OrdenOperativaEloquentModel::query()->sole();
        $this->assertSame(TipoOrdenOperativa::MANTENIMIENTO_PREVENTIVO, $order->tipo);
        $this->assertSame(OrigenOrdenOperativa::PROGRAMACION_PREVENTIVA, $order->origen);
        $this->assertSame(TipoActorOperativo::SISTEMA, $order->creada_por_tipo);
        $this->assertNull($order->creada_por_user_id);
        $this->assertSame('reportada', $order->estado->value);
        $this->assertSame('2028-01-31', $order->fecha_objetivo?->format('Y-m-d'));
        $this->assertDatabaseCount('asignaciones_orden_operativa', 0);
        $this->assertDatabaseHas('bitacora_orden_operativa', ['orden_operativa_id' => $order->id, 'actor_tipo' => 'sistema', 'actor_user_id' => null]);
        $this->assertSame('2028-02-29', $plan->refresh()->proxima_fecha_programada?->format('Y-m-d'));
        $this->assertSame($financialCounts, $this->financialCounts());

        $duplicate = $generate->execute(CarbonImmutable::parse('2028-01-31'), null, 100);
        $this->assertSame(0, $duplicate['procesadas']);
        $this->assertDatabaseCount('ordenes_operativas', 1);

        $limited = $generate->execute(CarbonImmutable::parse('2028-03-31'), null, 1);
        $this->assertSame(1, $limited['generadas']);
        $this->assertTrue($limited['limiteAlcanzado']);
        $this->assertSame('2028-03-31', $plan->refresh()->proxima_fecha_programada?->format('Y-m-d'));
        $next = $generate->execute(CarbonImmutable::parse('2028-03-31'), null, 1);
        $this->assertSame(1, $next['generadas']);
        $this->assertSame('2028-04-30', $plan->refresh()->proxima_fecha_programada?->format('Y-m-d'));
        $this->assertDatabaseCount('ordenes_operativas', 3);
        $this->assertDatabaseCount('ocurrencias_mantenimiento_preventivo', 3);
        $this->assertSame(3, DB::table('ocurrencias_mantenimiento_preventivo')->where('estado', 'generada')->count());

        $pending = OcurrenciaMantenimientoPreventivoEloquentModel::query()->create([
            'edificio_id' => $building->id,
            'plan_id' => $plan->id,
            'fecha_programada' => '2028-05-31',
            'estado' => EstadoOcurrenciaMantenimientoPreventivo::PENDIENTE,
        ]);
        $this->assertDatabaseRejects(static fn () => DB::table('ordenes_operativas')->where('id', $order->id)->update([
            'ocurrencia_mantenimiento_id' => $pending->id,
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('ocurrencias_mantenimiento_preventivo')->insert([
            'id' => fake()->uuid(), 'edificio_id' => $building->id, 'plan_id' => $plan->id,
            'fecha_programada' => '2028-06-30', 'estado' => 'pendiente',
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('ocurrencias_mantenimiento_preventivo')->insert([
            'id' => fake()->uuid(), 'edificio_id' => $building->id, 'plan_id' => $plan->id,
            'fecha_programada' => '2028-06-30', 'estado' => 'generada', 'procesada_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_recurrence_units_are_calculated_from_the_original_anchor(): void
    {
        $this->assertSame('2026-01-07', UnidadRecurrenciaMantenimiento::DIARIA->dateAt(CarbonImmutable::parse('2026-01-01'), 2, 3)->format('Y-m-d'));
        $this->assertSame('2026-02-16', UnidadRecurrenciaMantenimiento::SEMANAL->dateAt(CarbonImmutable::parse('2026-01-05'), 2, 3)->format('Y-m-d'));
        $this->assertSame('2028-02-29', UnidadRecurrenciaMantenimiento::MENSUAL->dateAt(CarbonImmutable::parse('2028-01-31'), 1, 1)->format('Y-m-d'));
        $this->assertSame('2028-03-31', UnidadRecurrenciaMantenimiento::MENSUAL->dateAt(CarbonImmutable::parse('2028-01-31'), 1, 2)->format('Y-m-d'));
        $this->assertSame('2026-05-31', UnidadRecurrenciaMantenimiento::MENSUAL->dateAt(CarbonImmutable::parse('2026-04-30'), 1, 1)->format('Y-m-d'));
        $this->assertSame('2029-02-28', UnidadRecurrenciaMantenimiento::ANUAL->dateAt(CarbonImmutable::parse('2028-02-29'), 1, 1)->format('Y-m-d'));
        $this->assertSame('2032-02-29', UnidadRecurrenciaMantenimiento::ANUAL->dateAt(CarbonImmutable::parse('2028-02-29'), 1, 4)->format('Y-m-d'));
        $this->assertTrue(UnidadRecurrenciaMantenimiento::MENSUAL->includesDate(CarbonImmutable::parse('2028-01-31'), 1, CarbonImmutable::parse('2028-03-31')));
        $this->assertFalse(UnidadRecurrenciaMantenimiento::MENSUAL->includesDate(CarbonImmutable::parse('2028-01-31'), 1, CarbonImmutable::parse('2028-03-30')));
    }

    public function test_reactivation_rejects_a_future_date_already_materialized_by_the_new_sequence(): void
    {
        Carbon::setTestNow('2027-12-01 08:00:00');
        [$administrator, $building] = $this->fixture();
        $plan = $this->createPlan($administrator, $building, ['codigo' => 'COLISION-FUTURA', 'dias_anticipacion' => 0]);
        $this->activate($administrator, $building, $plan, '2028-02-01');
        app(GeneratePreventiveMaintenanceOrdersAction::class)->execute(CarbonImmutable::parse('2028-02-01'), $building->id, 1);
        $this->actingAs($administrator)->patch(route('mantenimiento-preventivo.status', [$building, $plan]), ['estado' => 'inactivo'])->assertSessionHasNoErrors();

        $this->actingAs($administrator)->patch(route('mantenimiento-preventivo.status', [$building, $plan]), [
            'estado' => 'activo', 'proxima_fecha_programada' => '2028-01-01',
        ])->assertSessionHasErrors('proximaFechaProgramada');
        $this->assertSame(EstadoPlanMantenimientoPreventivo::INACTIVO, $plan->refresh()->estado);
    }

    public function test_blocked_plans_do_not_starve_later_plans_and_dry_run_previews_the_full_limited_backlog(): void
    {
        Carbon::setTestNow('2028-01-01 08:00:00');
        [$administrator, $building] = $this->fixture();
        [$provider] = $this->supplierAndContract($administrator, $building, '1791000000003', '2027-01-01', null);
        $blockedPlan = $this->createPlan($administrator, $building, [
            'codigo' => 'A-BLOQUEADO',
            'proveedor_id' => $provider->id,
            'dias_anticipacion' => 0,
        ]);
        $readyPlan = $this->createPlan($administrator, $building, [
            'codigo' => 'B-DISPONIBLE',
            'dias_anticipacion' => 0,
        ]);
        $this->activate($administrator, $building, $blockedPlan, '2028-01-31');
        $this->activate($administrator, $building, $readyPlan, '2028-01-31');
        DB::table('proveedor_edificio')->where('edificio_id', $building->id)->where('proveedor_id', $provider->id)->update(['estado' => 'inactivo']);

        $generate = app(GeneratePreventiveMaintenanceOrdersAction::class);
        $firstBatch = $generate->execute(CarbonImmutable::parse('2028-01-31'), $building->id, 1);
        $this->assertSame(1, $firstBatch['bloqueadas']);
        $secondBatch = $generate->execute(CarbonImmutable::parse('2028-01-31'), $building->id, 1);
        $this->assertSame(1, $secondBatch['generadas']);
        $this->assertDatabaseCount('ordenes_operativas', 1);
        $this->assertSame('2028-01-31', $blockedPlan->refresh()->proxima_fecha_programada?->format('Y-m-d'));

        $this->actingAs($administrator)->patch(route('mantenimiento-preventivo.status', [$building, $blockedPlan]), ['estado' => 'inactivo'])->assertSessionHasNoErrors();
        $preview = $generate->execute(CarbonImmutable::parse('2028-04-30'), $building->id, 2, true);
        $this->assertSame(2, $preview['procesadas']);
        $this->assertSame(2, $preview['generadas']);
        $this->assertTrue($preview['limiteAlcanzado']);
        $this->assertDatabaseCount('ordenes_operativas', 1);
        $this->assertSame('2028-02-29', $readyPlan->refresh()->proxima_fecha_programada?->format('Y-m-d'));

        $actual = $generate->execute(CarbonImmutable::parse('2028-04-30'), $building->id, 2);
        $this->assertSame(2, $actual['generadas']);
        $this->assertDatabaseCount('ordenes_operativas', 3);
        $this->assertSame('2028-04-30', $readyPlan->refresh()->proxima_fecha_programada?->format('Y-m-d'));
    }

    public function test_invalid_supplier_or_contract_blocks_occurrence_and_retry_or_omission_preserves_traceability(): void
    {
        Carbon::setTestNow('2026-10-01 08:00:00');
        [$administrator, $building] = $this->fixture();
        [$provider, $contract] = $this->supplierAndContract($administrator, $building, '1791000000001', '2026-01-01', '2026-11-30');
        $plan = $this->createPlan($administrator, $building, ['proveedor_id' => $provider->id, 'contrato_id' => $contract->id]);
        $this->activate($administrator, $building, $plan, '2026-10-01');
        $generate = app(GeneratePreventiveMaintenanceOrdersAction::class);
        $generate->execute(CarbonImmutable::parse('2026-10-01'), null, 100);
        $this->assertSame('2026-11-01', $plan->refresh()->proxima_fecha_programada?->format('Y-m-d'));

        DB::table('proveedor_edificio')->where('edificio_id', $building->id)->where('proveedor_id', $provider->id)->update(['estado' => 'inactivo']);
        $blocked = $generate->execute(CarbonImmutable::parse('2026-11-01'), null, 100);
        $this->assertSame(1, $blocked['bloqueadas']);
        $occurrence = OcurrenciaMantenimientoPreventivoEloquentModel::query()->where('estado', 'bloqueada')->sole();
        $blockedAt = $occurrence->procesada_at;
        $this->assertStringContainsString('dejó de estar activo', (string) $occurrence->motivo);
        $this->assertSame('2026-11-01', $plan->refresh()->proxima_fecha_programada?->format('Y-m-d'));
        $this->assertDatabaseCount('ordenes_operativas', 1);
        $this->assertDatabaseHas('bitacora_plan_mantenimiento', ['plan_id' => $plan->id, 'tipo' => 'bloqueo', 'actor_tipo' => 'sistema']);

        DB::table('proveedor_edificio')->where('edificio_id', $building->id)->where('proveedor_id', $provider->id)->update(['estado' => 'activo']);
        $this->assertDatabaseHas('proveedor_edificio', ['edificio_id' => $building->id, 'proveedor_id' => $provider->id, 'estado' => 'activo']);
        $this->assertNotNull(app(ProveedoresOperacionesReadInterface::class)->activeProvider($building->id, $provider->id));
        $this->assertSame(EstadoPlanMantenimientoPreventivo::ACTIVO, $plan->refresh()->estado);
        $this->assertSame('2026-11-01', $plan->proxima_fecha_programada?->format('Y-m-d'));
        $this->actingAs($administrator)->post(route('mantenimiento-preventivo.occurrences.retry', [$building, $plan, $occurrence]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('bitacora_plan_mantenimiento', ['plan_id' => $plan->id, 'tipo' => 'reintento', 'actor_user_id' => $administrator->id]);
        $this->assertNull($occurrence->refresh()->motivo);
        $this->assertSame(EstadoOcurrenciaMantenimientoPreventivo::GENERADA, $occurrence->refresh()->estado);
        $this->assertFalse($occurrence->procesada_at->equalTo($blockedAt));
        $this->assertDatabaseCount('ordenes_operativas', 2);
        $this->assertSame('2026-12-01', $plan->refresh()->proxima_fecha_programada?->format('Y-m-d'));
        $this->assertDatabaseHas('bitacora_plan_mantenimiento', ['plan_id' => $plan->id, 'tipo' => 'reintento', 'actor_user_id' => $administrator->id]);

        $generate->execute(CarbonImmutable::parse('2026-12-01'), null, 100);
        $secondBlocked = OcurrenciaMantenimientoPreventivoEloquentModel::query()->where('estado', 'bloqueada')->sole();
        $this->assertStringContainsString('no está vigente', (string) $secondBlocked->motivo);
        $this->actingAs($administrator)->patch(route('mantenimiento-preventivo.occurrences.omit', [$building, $plan, $secondBlocked]), ['motivo' => 'Proveedor pendiente de renovación.'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoOcurrenciaMantenimientoPreventivo::OMITIDA, $secondBlocked->refresh()->estado);
        $this->assertSame('2027-01-01', $plan->refresh()->proxima_fecha_programada?->format('Y-m-d'));
    }

    public function test_pause_discards_open_occurrence_reactivation_uses_new_anchor_and_database_history_is_immutable(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        [$administrator, $building] = $this->fixture();
        [$provider] = $this->supplierAndContract($administrator, $building, '1791000000002', '2026-01-01', null);
        $plan = $this->createPlan($administrator, $building, ['proveedor_id' => $provider->id]);
        $this->activate($administrator, $building, $plan, '2026-10-05');
        $this->assertDatabaseRejects(static fn () => DB::table('planes_mantenimiento_preventivo')->where('id', $plan->id)->update([
            'fecha_ancla' => '2026-10-06',
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('planes_mantenimiento_preventivo')->where('id', $plan->id)->update([
            'estado_actualizado_at' => now()->addDay(),
        ]));
        DB::table('proveedor_edificio')->where('edificio_id', $building->id)->where('proveedor_id', $provider->id)->update(['estado' => 'inactivo']);
        app(GeneratePreventiveMaintenanceOrdersAction::class)->execute(CarbonImmutable::parse('2026-10-05'), null, 100);
        $blocked = OcurrenciaMantenimientoPreventivoEloquentModel::query()->sole();
        $this->assertDatabaseRejects(static fn () => DB::table('planes_mantenimiento_preventivo')->where('id', $plan->id)->update(['titulo' => 'Cambio directo inválido']));

        $this->actingAs($administrator)->patch(route('mantenimiento-preventivo.status', [$building, $plan]), ['estado' => 'inactivo'])->assertSessionHasNoErrors();
        $this->assertSame(EstadoOcurrenciaMantenimientoPreventivo::OMITIDA, $blocked->refresh()->estado);
        $this->assertNull($plan->refresh()->proxima_fecha_programada);
        $this->assertDatabaseRejects(static fn () => DB::table('planes_mantenimiento_preventivo')->where('id', $plan->id)->update([
            'estado' => 'activo', 'fecha_ancla' => '2027-01-15', 'proxima_fecha_programada' => '2027-01-15',
        ]));
        $this->actingAs($administrator)->patch(route('mantenimiento-preventivo.status', [$building, $plan]), [
            'estado' => 'activo', 'proxima_fecha_programada' => '2026-10-05',
        ])->assertSessionHasErrors('proximaFechaProgramada');
        $this->assertSame(EstadoPlanMantenimientoPreventivo::INACTIVO, $plan->refresh()->estado);
        Carbon::setTestNow('2026-12-01 10:00:00');
        DB::table('proveedor_edificio')->where('edificio_id', $building->id)->where('proveedor_id', $provider->id)->update(['estado' => 'activo']);
        $this->activate($administrator, $building, $plan, '2027-01-15');
        $this->assertSame('2027-01-15', $plan->refresh()->fecha_ancla?->format('Y-m-d'));
        $this->assertSame(0, $plan->secuencia_siguiente);
        $this->assertSame(0, app(GeneratePreventiveMaintenanceOrdersAction::class)->execute(CarbonImmutable::parse('2026-12-01'), null, 100)['procesadas']);

        $log = BitacoraPlanMantenimientoEloquentModel::query()->firstOrFail();
        $this->assertDatabaseRejects(static fn () => DB::table('bitacora_plan_mantenimiento')->where('id', $log->id)->update(['detalle' => '{}']));
        $this->assertDatabaseRejects(static fn () => DB::table('planes_mantenimiento_preventivo')->where('id', $plan->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('ocurrencias_mantenimiento_preventivo')->where('id', $blocked->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('ordenes_operativas')->insert([
            'id' => fake()->uuid(), 'edificio_id' => $building->id, 'numero' => 'OPR-2026-999999', 'tipo' => 'mantenimiento_preventivo',
            'origen' => 'manual', 'titulo' => 'Inválida', 'descripcion' => 'Inválida', 'prioridad' => 'media', 'estado' => 'reportada',
            'ubicacion_detalle' => 'Prueba', 'creada_por_user_id' => $administrator->id, 'creada_por_tipo' => 'usuario', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_command_supports_dry_run_and_scheduler_registers_daily_single_server_batch(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        [$administrator, $building] = $this->fixture();
        $plan = $this->createPlan($administrator, $building);
        $this->activate($administrator, $building, $plan, '2026-10-05');

        $this->artisan('operaciones:generar-mantenimiento-preventivo', ['--fecha' => '2026-10-05', '--limite' => 10, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('ordenes_operativas', 0);
        $this->artisan('operaciones:generar-mantenimiento-preventivo', ['--fecha' => '2026-02-31'])
            ->expectsOutput('La fecha operativa debe ser una fecha válida con formato YYYY-MM-DD.')
            ->assertFailed();
        $this->assertDatabaseCount('ordenes_operativas', 0);
        $this->artisan('operaciones:generar-mantenimiento-preventivo', ['--edificio' => 'edificio-invalido'])
            ->expectsOutput('El edificio debe ser un UUID válido.')
            ->assertFailed();
        $this->artisan('operaciones:generar-mantenimiento-preventivo', ['--fecha' => '2026-10-05', '--edificio' => $building->id, '--limite' => 10])->assertSuccessful();
        $this->assertDatabaseCount('ordenes_operativas', 1);

        $event = collect(app(Schedule::class)->events())->first(static fn ($event): bool => str_contains((string) $event->command, 'operaciones:generar-mantenimiento-preventivo'));
        $this->assertNotNull($event);
        $this->assertSame('0 2 * * *', $event->getExpression());
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
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
            'nombre' => 'Edificio Preventivo '.$number, 'ruc' => str_pad((string) $number, 13, '0', STR_PAD_LEFT),
            'direccion' => 'Dirección '.$number, 'ciudad' => 'Quito', 'telefono' => null, 'correo' => null, 'responsable' => null,
        ], $administrator->id);

        return EdificioEloquentModel::query()->findOrFail($created->id());
    }

    private function createPlan(UserEloquentModel $actor, EdificioEloquentModel $building, array $overrides = []): PlanMantenimientoPreventivoEloquentModel
    {
        $before = PlanMantenimientoPreventivoEloquentModel::query()->pluck('id');
        $this->actingAs($actor)->post(route('mantenimiento-preventivo.store', $building), $this->planData($overrides))->assertSessionHasNoErrors();

        return PlanMantenimientoPreventivoEloquentModel::query()->whereNotIn('id', $before)->sole();
    }

    private function activate(UserEloquentModel $actor, EdificioEloquentModel $building, PlanMantenimientoPreventivoEloquentModel $plan, string $date): void
    {
        $this->actingAs($actor)->patch(route('mantenimiento-preventivo.status', [$building, $plan]), [
            'estado' => 'activo', 'proxima_fecha_programada' => $date,
        ])->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function planData(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'ASCENSOR-T1', 'titulo' => 'Ascensor principal', 'descripcion' => 'Inspección preventiva del ascensor.',
            'prioridad' => 'alta', 'unidad_recurrencia' => 'mensual', 'intervalo_recurrencia' => 1, 'dias_anticipacion' => 2,
            'torre_id' => null, 'piso_id' => null, 'departamento_id' => null, 'parqueadero_id' => null, 'bodega_id' => null,
            'ubicacion_detalle' => 'Cuarto de máquinas', 'proveedor_id' => null, 'contrato_id' => null,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function updateData(PlanMantenimientoPreventivoEloquentModel $plan, array $overrides = []): array
    {
        return [...$this->planData($overrides), 'updated_at' => $plan->fresh()->updated_at?->toIso8601String()];
    }

    /** @return array{ProveedorEloquentModel, ContratoProveedorEloquentModel} */
    private function supplierAndContract(UserEloquentModel $actor, EdificioEloquentModel $building, string $identification, string $start, ?string $end): array
    {
        $this->actingAs($actor)->post(route('proveedores.store', $building), [
            'tipo_persona' => 'persona_juridica', 'nombres' => null, 'apellidos' => null, 'razon_social' => 'Proveedor '.$identification,
            'tipo_identificacion' => 'ruc', 'identificacion' => $identification, 'telefono' => '022111111', 'celular' => null,
            'correo' => $identification.'@example.test', 'direccion' => 'Dirección proveedor', 'nombre_comercial' => 'Proveedor preventivo',
            'contacto' => 'Contacto', 'telefono_comercial' => null, 'correo_comercial' => null, 'direccion_comercial' => null,
            'dias_credito' => 30, 'observaciones' => null,
        ])->assertSessionHasNoErrors();
        $provider = ProveedorEloquentModel::query()->whereHas('tercero', static fn ($query) => $query->where('identificacion', $identification))->sole();
        $reference = 'PREV-'.substr($identification, -4);
        $this->actingAs($actor)->post(route('contratos-proveedor.store', $building), [
            'proveedor_id' => $provider->id, 'referencia' => $reference, 'objeto' => 'Mantenimiento preventivo',
            'fecha_inicio' => $start, 'fecha_fin' => $end, 'monto_total' => '1000.0000', 'observaciones' => null,
        ])->assertSessionHasNoErrors();
        $contract = ContratoProveedorEloquentModel::query()->where('edificio_id', $building->id)->where('referencia', $reference)->sole();
        $this->actingAs($actor)->patch(route('contratos-proveedor.register', [$building, $contract]))->assertSessionHasNoErrors();

        return [$provider, $contract->refresh()];
    }

    private function addMember(EdificioEloquentModel $building, UserEloquentModel $member, RolEdificio $role, UserEloquentModel $actor): void
    {
        $building->usuarios()->attach($member->id, ['creado_por_user_id' => $actor->id]);
        DB::table('edificio_usuario_roles')->insert([
            'edificio_id' => $building->id, 'user_id' => $member->id, 'rol_codigo' => $role->value,
            'asignado_por_user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function financialCounts(): array
    {
        return collect(['gastos', 'cuentas_por_pagar', 'desembolsos', 'movimientos_tesoreria'])
            ->mapWithKeys(static fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('La base de datos permitió una mutación protegida de mantenimiento preventivo.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
