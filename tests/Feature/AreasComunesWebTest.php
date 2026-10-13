<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Src\AreasComunes\Infrastructure\Models\BitacoraAreaComunEloquentModel;
use Src\AreasComunes\Infrastructure\Models\EspacioComunEloquentModel;
use Src\AreasComunes\Infrastructure\Models\HorarioReglaEspacioComunEloquentModel;
use Src\AreasComunes\Infrastructure\Models\ReglaEspacioComunEloquentModel;
use Src\AreasComunes\Infrastructure\Models\ReservaEspacioComunEloquentModel;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Edificio\Infrastructure\Models\TorreEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Models\TerceroEloquentModel;
use Tests\TestCase;

final class AreasComunesWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_routes_enforce_authentication_exact_role_matrix_and_building_isolation(): void
    {
        [$administrator, $building] = $this->fixture();
        [$otherAdministrator, $otherBuilding] = $this->fixture();
        $manager = UserEloquentModel::factory()->create();
        $viewer = UserEloquentModel::factory()->create();
        $operationsManager = UserEloquentModel::factory()->create();
        $propertyManager = UserEloquentModel::factory()->create();
        $financeManager = UserEloquentModel::factory()->create();
        $this->addMember($building, $manager, RolEdificio::GESTOR_AREAS_COMUNES, $administrator);
        $this->addMember($building, $viewer, RolEdificio::CONSULTA, $administrator);
        $this->addMember($building, $operationsManager, RolEdificio::GESTOR_OPERACIONES, $administrator);
        $this->addMember($building, $propertyManager, RolEdificio::GESTOR_PROPIEDAD, $administrator);
        $this->addMember($building, $financeManager, RolEdificio::GESTOR_FINANZAS, $administrator);

        $this->get(route('areas-comunes.index'))->assertRedirect(route('login'));
        $this->get(route('areas-comunes.create'))->assertRedirect(route('login'));
        $this->get(route('reservas-areas-comunes.index'))->assertRedirect(route('login'));
        $this->get(route('reservas-areas-comunes.create'))->assertRedirect(route('login'));
        foreach (['areas-comunes.destroy', 'areas-comunes.rules.destroy', 'reservas-areas-comunes.destroy'] as $routeName) {
            $this->assertFalse(Route::has($routeName));
        }

        $this->actingAs($manager)->get(route('areas-comunes.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('AreaComun/index')->has('edificios', 1)->where('edificios.0.id', $building->id));
        $this->actingAs($manager)->get(route('reservas-areas-comunes.create'))->assertOk();
        $this->actingAs($viewer)->get(route('areas-comunes.index'))->assertOk();
        $this->actingAs($viewer)->get(route('reservas-areas-comunes.index'))->assertOk();
        $this->actingAs($viewer)->get(route('areas-comunes.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('reservas-areas-comunes.create'))->assertForbidden();
        $this->actingAs($operationsManager)->get(route('areas-comunes.index'))->assertForbidden();
        $this->actingAs($propertyManager)->get(route('areas-comunes.index'))->assertForbidden();
        $this->actingAs($financeManager)->get(route('areas-comunes.index'))->assertForbidden();

        $space = $this->createSpace($administrator, $building);
        $this->actingAs($viewer)->get(route('areas-comunes.show', [$building, $space]))->assertOk();
        $this->actingAs($viewer)->put(route('areas-comunes.update', [$building, $space]), $this->spaceUpdateData($space))->assertForbidden();
        $this->actingAs($otherAdministrator)->get(route('areas-comunes.show', [$building, $space]))->assertNotFound();
        $this->actingAs($administrator)->get(route('areas-comunes.show', [$otherBuilding, $space]))->assertNotFound();

        $access = app(AccesoEdificioRepositoryInterface::class);
        foreach ([PermisoEdificio::AREAS_COMUNES_VER, PermisoEdificio::AREAS_COMUNES_GESTIONAR, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER, PermisoEdificio::RESERVAS_AREAS_COMUNES_REGISTRAR, PermisoEdificio::RESERVAS_AREAS_COMUNES_APROBAR, PermisoEdificio::RESERVAS_AREAS_COMUNES_CANCELAR] as $permission) {
            $this->assertTrue($access->hasPermission($manager->id, $building->id, $permission));
        }
        $this->assertTrue($access->hasPermission($viewer->id, $building->id, PermisoEdificio::AREAS_COMUNES_VER));
        $this->assertTrue($access->hasPermission($viewer->id, $building->id, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER));
        $this->assertFalse($access->hasPermission($viewer->id, $building->id, PermisoEdificio::AREAS_COMUNES_GESTIONAR));
        $this->assertFalse($access->hasPermission($operationsManager->id, $building->id, PermisoEdificio::AREAS_COMUNES_VER));
    }

    public function test_area_read_permission_does_not_expose_reservations_without_reservation_read_permission(): void
    {
        [$administrator, $building, , $occupation] = $this->fixture();
        $viewer = UserEloquentModel::factory()->create();
        $this->addMember($building, $viewer, RolEdificio::CONSULTA, $administrator);
        $space = $this->createSpace($administrator, $building);
        $this->addRule($administrator, $building, $space, $this->ruleData());
        $this->createReservation($administrator, $building, $space, $occupation);
        DB::table('rol_permisos')->where('rol_codigo', RolEdificio::CONSULTA->value)
            ->where('permiso_codigo', PermisoEdificio::RESERVAS_AREAS_COMUNES_VER->value)->delete();

        $this->actingAs($viewer)->get(route('areas-comunes.show', [$building, $space]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('espacio.puedeVerReservas', false)
                ->has('espacio.reservas', 0)
                ->has('espacio.bitacora', 2));
        $this->actingAs($viewer)->get(route('areas-comunes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('espacios.data.0.puedeVerReservas', false)
                ->where('espacios.data.0.reservasFuturas', null));
        $this->actingAs($viewer)->get(route('reservas-areas-comunes.index'))->assertForbidden();
    }

    public function test_space_is_normalized_scoped_versioned_and_cannot_be_deleted_or_cross_buildings(): void
    {
        [$administrator, $building] = $this->fixture();
        $otherBuilding = $this->buildingFor($administrator);
        $otherTower = TorreEloquentModel::query()->where('edificio_id', $otherBuilding->id)->sole();

        $this->actingAs($administrator)->post(route('areas-comunes.store', $building), $this->spaceData(['codigo' => ' salon-social ']))->assertSessionHasNoErrors();
        $space = EspacioComunEloquentModel::query()->sole();
        $this->assertSame('SALON-SOCIAL', $space->codigo);
        $this->assertSame('America/Guayaquil', $building->refresh()->timezone);
        $this->assertDatabaseCount('bitacora_areas_comunes', 1);
        $this->actingAs($administrator)->post(route('areas-comunes.store', $building), $this->spaceData())->assertSessionHasErrors();
        $this->actingAs($administrator)->post(route('areas-comunes.store', $otherBuilding), $this->spaceData())->assertSessionHasNoErrors();
        $this->actingAs($administrator)->post(route('areas-comunes.store', $building), $this->spaceData([
            'codigo' => 'OTRO', 'torre_id' => $otherTower->id, 'ubicacion_detalle' => null,
        ]))->assertSessionHasErrors();

        $stale = $this->spaceUpdateData($space, ['nombre' => 'Nombre nuevo']);
        $this->actingAs($administrator)->put(route('areas-comunes.update', [$building, $space]), $stale)->assertSessionHasNoErrors();
        $this->actingAs($administrator)->put(route('areas-comunes.update', [$building, $space]), $stale)->assertSessionHasErrors();
        $this->assertSame('Nombre nuevo', $space->refresh()->nombre);
        $this->assertDatabaseRejects(static fn () => DB::table('espacios_comunes')->where('id', $space->id)->update(['edificio_id' => $otherBuilding->id]));
        $this->assertDatabaseRejects(static fn () => DB::table('espacios_comunes')->where('id', $space->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('edificios')->where('id', $building->id)->update(['timezone' => 'Zona/Inexistente']));
    }

    public function test_rule_versions_close_the_previous_period_and_preserve_immutable_non_overlapping_schedules(): void
    {
        [$administrator, $building] = $this->fixture();
        $space = $this->createSpace($administrator, $building);

        $this->addRule($administrator, $building, $space, $this->ruleData(['vigente_desde' => '2026-10-08']));
        $first = ReglaEspacioComunEloquentModel::query()->sole();
        $this->assertNull($first->vigente_hasta);
        $this->actingAs($administrator)->post(route('areas-comunes.rules.store', [$building, $space]), $this->ruleData([
            'vigente_desde' => '2026-10-08',
        ]))->assertSessionHasErrors(['vigenteDesde']);
        $this->addRule($administrator, $building, $space, $this->ruleData(['vigente_desde' => '2026-11-01', 'requiere_aprobacion' => true]));
        $this->assertSame('2026-11-01', $first->refresh()->vigente_hasta?->format('Y-m-d'));
        $this->assertDatabaseCount('reglas_espacio_comun', 2);
        $this->actingAs($administrator)->post(route('areas-comunes.rules.store', [$building, $space]), $this->ruleData(['vigente_desde' => '2026-10-20']))->assertSessionHasErrors();
        $this->actingAs($administrator)->post(route('areas-comunes.rules.store', [$building, $space]), $this->ruleData([
            'vigente_desde' => '2026-12-01',
            'horarios' => [
                ['dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '12:00'],
                ['dia_semana' => 1, 'hora_inicio' => '11:00', 'hora_fin' => '15:00'],
            ],
        ]))->assertSessionHasErrors();

        $schedule = HorarioReglaEspacioComunEloquentModel::query()->firstOrFail();
        $this->assertDatabaseRejects(static fn () => DB::table('reglas_espacio_comun')->where('id', $first->id)->update(['aforo_maximo' => 1]));
        $this->assertDatabaseRejects(static fn () => DB::table('horarios_regla_espacio_comun')->where('id', $schedule->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('reglas_espacio_comun')->where('id', $first->id)->delete());
    }

    public function test_replacing_a_rule_cannot_rewrite_the_current_local_day(): void
    {
        [$administrator, $building] = $this->fixture();
        $space = $this->createSpace($administrator, $building);
        $this->addRule($administrator, $building, $space, $this->ruleData(['vigente_desde' => '2026-10-07']));
        Carbon::setTestNow('2026-10-08 12:00:00');

        $this->actingAs($administrator)->post(route('areas-comunes.rules.store', [$building, $space]), $this->ruleData([
            'vigente_desde' => '2026-10-08',
        ]))->assertSessionHasErrors(['vigenteDesde']);
        $this->assertDatabaseCount('reglas_espacio_comun', 1);
        $this->assertNull(ReglaEspacioComunEloquentModel::query()->sole()->vigente_hasta);
    }

    public function test_automatic_confirmation_uses_building_timezone_active_occupation_and_rule_limits(): void
    {
        [$administrator, $building, , $occupation] = $this->fixture();
        $space = $this->createSpace($administrator, $building);
        $this->addRule($administrator, $building, $space, $this->ruleData(['vigente_desde' => '2026-10-08']));

        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation))->assertSessionHasNoErrors();
        $reservation = ReservaEspacioComunEloquentModel::query()->sole();
        $this->assertSame('confirmada', $reservation->estado->value);
        $this->assertSame('2026-10-12 15:00:00', $reservation->inicio_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-12 16:00:00', $reservation->fin_at?->format('Y-m-d H:i:s'));
        $this->assertSame($occupation->id, $reservation->reservante_snapshot['id']);
        $this->assertDatabaseHas('bitacora_areas_comunes', ['reserva_id' => $reservation->id, 'tipo' => 'reserva_creada']);

        $ineligible = $this->occupantFor($building, $this->departmentFor($building, 'B-202'), '1710000999', false);
        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($ineligible, ['inicio' => '2026-10-13T10:00', 'fin' => '2026-10-13T11:00']))->assertSessionHasErrors();
        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation, ['inicio' => '2026-10-12T06:00', 'fin' => '2026-10-12T07:00']))->assertSessionHasErrors();
        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation, ['inicio' => '2026-10-12T12:00', 'fin' => '2026-10-12T16:00']))->assertSessionHasErrors();
        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation, ['inicio' => '2026-10-12T17:00', 'fin' => '2026-10-12T18:00', 'cantidad_asistentes' => 51]))->assertSessionHasErrors();
        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation, ['inicio' => '2026-10-12T21:30', 'fin' => '2026-10-12T22:00']))->assertSessionHasNoErrors();
    }

    public function test_reservation_options_use_each_building_local_day_for_occupations(): void
    {
        Carbon::setTestNow('2026-09-01 23:00:00 UTC');
        [$administrator, $building, $department] = $this->fixture();
        $building->update(['timezone' => 'Pacific/Kiritimati']);
        $localTodayOccupation = $this->occupantFor($building, $department, '1710000888', true, '2026-09-02');

        $this->actingAs($administrator)->get(route('reservas-areas-comunes.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ocupaciones', 2)
                ->where('ocupaciones', fn ($occupations): bool => $occupations->contains('id', $localTodayOccupation->id)));
    }

    public function test_buffers_prevent_overlap_contiguous_blocks_are_allowed_and_cancellation_releases_time(): void
    {
        [$administrator, $building, , $occupation] = $this->fixture();
        $space = $this->createSpace($administrator, $building);
        $this->addRule($administrator, $building, $space, $this->ruleData(['vigente_desde' => '2026-10-08', 'buffer_antes_minutos' => 30, 'buffer_despues_minutos' => 30]));
        $this->createReservation($administrator, $building, $space, $occupation, ['inicio' => '2026-10-12T10:00', 'fin' => '2026-10-12T11:00']);
        $first = ReservaEspacioComunEloquentModel::query()->sole();

        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation, ['inicio' => '2026-10-12T11:00', 'fin' => '2026-10-12T12:00']))->assertSessionHasErrors();
        $this->createReservation($administrator, $building, $space, $occupation, ['inicio' => '2026-10-12T12:00', 'fin' => '2026-10-12T13:00']);
        $this->assertDatabaseCount('reservas_espacio_comun', 2);
        $this->actingAs($administrator)->patch(route('reservas-areas-comunes.cancel', [$building, $first]), ['motivo' => 'Cambio solicitado por el residente.'])->assertSessionHasNoErrors();
        $this->assertSame('cancelada', $first->refresh()->estado->value);
        $this->createReservation($administrator, $building, $space, $occupation, ['inicio' => '2026-10-12T09:30', 'fin' => '2026-10-12T10:30']);
        $this->assertDatabaseCount('reservas_espacio_comun', 3);
    }

    public function test_configurable_approval_rechecks_conflicts_and_rejection_or_cancellation_require_reason(): void
    {
        [$administrator, $building, , $occupation] = $this->fixture();
        $space = $this->createSpace($administrator, $building);
        $this->addRule($administrator, $building, $space, $this->ruleData(['vigente_desde' => '2026-10-08', 'requiere_aprobacion' => true]));
        $this->createReservation($administrator, $building, $space, $occupation);
        $this->createReservation($administrator, $building, $space, $occupation);
        [$first, $second] = ReservaEspacioComunEloquentModel::query()->orderBy('created_at')->get()->all();
        $this->assertSame('pendiente', $first->estado->value);
        $this->assertSame('pendiente', $second->estado->value);

        $this->actingAs($administrator)->patch(route('reservas-areas-comunes.approve', [$building, $first]))->assertSessionHasNoErrors();
        $this->assertSame('confirmada', $first->refresh()->estado->value);
        $this->actingAs($administrator)->patch(route('reservas-areas-comunes.approve', [$building, $second]))->assertSessionHasErrors();
        $this->actingAs($administrator)->patch(route('reservas-areas-comunes.reject', [$building, $second]), ['motivo' => ''])->assertSessionHasErrors();
        $this->actingAs($administrator)->patch(route('reservas-areas-comunes.reject', [$building, $second]), ['motivo' => 'Horario asignado a otra solicitud.'])->assertSessionHasNoErrors();
        $this->assertSame('rechazada', $second->refresh()->estado->value);
        $this->actingAs($administrator)->patch(route('reservas-areas-comunes.cancel', [$building, $first]), ['motivo' => ''])->assertSessionHasErrors();
    }

    public function test_occupation_cannot_end_with_active_reservations_and_is_rechecked_on_approval(): void
    {
        [$administrator, $building, $department, $occupation] = $this->fixture();
        $space = $this->createSpace($administrator, $building);
        $this->addRule($administrator, $building, $space, $this->ruleData(['requiere_aprobacion' => true]));
        $this->createReservation($administrator, $building, $space, $occupation);
        $reservation = ReservaEspacioComunEloquentModel::query()->sole();

        $this->actingAs($administrator)->patch(route('departamentos.residentes.finalize', [$building, $department, $occupation]), [
            'fecha_fin' => '2026-10-07',
        ])->assertSessionHasErrors(['fechaFin']);
        $this->assertSame('activa', $occupation->refresh()->estado->value);

        $occupation->fill(['fecha_fin' => '2026-10-07', 'estado' => 'finalizada'])->save();
        $this->actingAs($administrator)->patch(route('reservas-areas-comunes.approve', [$building, $reservation]))
            ->assertSessionHasErrors(['estado']);
        $this->assertSame('pendiente', $reservation->refresh()->estado->value);
    }

    public function test_future_confirmed_reservation_blocks_space_inactivation_and_database_history_is_immutable(): void
    {
        [$administrator, $building, , $occupation] = $this->fixture();
        $space = $this->createSpace($administrator, $building);
        $this->addRule($administrator, $building, $space, $this->ruleData(['vigente_desde' => '2026-10-08']));
        $this->createReservation($administrator, $building, $space, $occupation);
        $reservation = ReservaEspacioComunEloquentModel::query()->sole();
        $log = BitacoraAreaComunEloquentModel::query()->where('reserva_id', $reservation->id)->sole();

        $this->actingAs($administrator)->patch(route('areas-comunes.status', [$building, $space]), ['estado' => 'inactivo'])->assertSessionHasErrors();
        $this->assertDatabaseRejects(static fn () => DB::table('reservas_espacio_comun')->where('id', $reservation->id)->update(['cantidad_asistentes' => 2]));
        $this->assertDatabaseRejects(static fn () => DB::table('reservas_espacio_comun')->where('id', $reservation->id)->update(['estado' => 'rechazada', 'resuelta_por_user_id' => $administrator->id, 'resuelta_at' => now(), 'motivo_resolucion' => 'No permitido']));
        $this->assertDatabaseRejects(static fn () => DB::table('reservas_espacio_comun')->where('id', $reservation->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('bitacora_areas_comunes')->where('id', $log->id)->update(['detalle' => '{}']));

        $invalidSnapshot = $reservation->getAttributes();
        $invalidSnapshot['id'] = (string) Str::uuid();
        $invalidSnapshot['estado'] = 'pendiente';
        $invalidSnapshot['resuelta_por_user_id'] = null;
        $invalidSnapshot['resuelta_at'] = null;
        $invalidSnapshot['motivo_resolucion'] = null;
        $invalidSnapshot['reservante_snapshot'] = 'null';
        $this->assertDatabaseRejects(static fn () => DB::table('reservas_espacio_comun')->insert($invalidSnapshot));

        $invalidSnapshot = $reservation->getAttributes();
        $invalidSnapshot['id'] = (string) Str::uuid();
        $invalidSnapshot['estado'] = 'pendiente';
        $invalidSnapshot['resuelta_por_user_id'] = null;
        $invalidSnapshot['resuelta_at'] = null;
        $invalidSnapshot['motivo_resolucion'] = null;
        $invalidSnapshot['regla_snapshot'] = '{}';
        $this->assertDatabaseRejects(static fn () => DB::table('reservas_espacio_comun')->insert($invalidSnapshot));

        $this->actingAs($administrator)->patch(route('reservas-areas-comunes.cancel', [$building, $reservation]), ['motivo' => 'Espacio fuera de servicio.'])->assertSessionHasNoErrors();
        $this->actingAs($administrator)->patch(route('areas-comunes.status', [$building, $space]), ['estado' => 'inactivo'])->assertSessionHasNoErrors();
        $this->assertSame('inactivo', $space->refresh()->estado->value);
    }

    public function test_rule_and_reservation_freeze_timezone_and_building_cannot_change_it_afterward(): void
    {
        [$administrator, $building, , $occupation] = $this->fixture();
        $space = $this->createSpace($administrator, $building);
        $this->addRule($administrator, $building, $space, $this->ruleData());
        $rule = ReglaEspacioComunEloquentModel::query()->sole();
        $this->assertSame('America/Guayaquil', $rule->timezone);

        $this->createReservation($administrator, $building, $space, $occupation);
        $reservation = ReservaEspacioComunEloquentModel::query()->sole();
        $this->assertSame('America/Guayaquil', $reservation->regla_snapshot['timezone']);
        $this->actingAs($administrator)->get(route('reservas-areas-comunes.show', [$building, $reservation]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reserva.timezone', 'America/Guayaquil')
                ->where('reserva.reglaSnapshot.timezone', 'America/Guayaquil')
                ->where('reserva.puedeAprobar', false)
                ->where('reserva.puedeCancelar', true));

        $this->actingAs($administrator)->put(route('edificios.update', $building), [
            'nombre' => $building->nombre,
            'ruc' => $building->ruc,
            'direccion' => $building->direccion,
            'ciudad' => $building->ciudad,
            'timezone' => 'America/New_York',
            'telefono' => $building->telefono,
            'correo' => $building->correo,
            'responsable' => $building->responsable,
        ])->assertSessionHasErrors(['timezone']);
        $this->assertSame('America/Guayaquil', $building->refresh()->timezone);
        $this->assertDatabaseRejects(static fn () => DB::table('reglas_espacio_comun')->where('id', $rule->id)->update(['timezone' => 'America/New_York']));
        $this->assertDatabaseRejects(static fn () => DB::table('edificios')->where('id', $building->id)->update(['timezone' => 'America/New_York']));

        $otherSpace = $this->createSpace($administrator, $building, ['codigo' => 'TERRAZA', 'nombre' => 'Terraza']);
        $mismatchedRule = $rule->getAttributes();
        $mismatchedRule['id'] = (string) Str::uuid();
        $mismatchedRule['espacio_comun_id'] = $otherSpace->id;
        $mismatchedRule['timezone'] = 'America/New_York';
        $this->assertDatabaseRejects(static fn () => DB::table('reglas_espacio_comun')->insert($mismatchedRule));
    }

    public function test_rule_rejects_inconsistent_advance_notice_and_reservation_rejects_dst_gaps_or_repeated_times(): void
    {
        [$administrator, $building, , $occupation] = $this->fixture();
        $building->update(['timezone' => 'America/New_York']);
        $space = $this->createSpace($administrator, $building);
        $this->actingAs($administrator)->post(route('areas-comunes.rules.store', [$building, $space]), $this->ruleData([
            'antelacion_minima_minutos' => 2881,
            'antelacion_maxima_dias' => 2,
        ]))->assertSessionHasErrors(['antelacionMinimaMinutos']);
        $this->addRule($administrator, $building, $space, $this->ruleData([
            'horarios' => [['dia_semana' => 7, 'hora_inicio' => '00:00', 'hora_fin' => '04:00']],
        ]));

        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation, [
            'inicio' => '2026-11-01T01:30',
            'fin' => '2026-11-01T02:30',
        ]))->assertSessionHasErrors(['inicio']);
        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation, [
            'inicio' => '2027-03-14T02:30',
            'fin' => '2027-03-14T03:30',
        ]))->assertSessionHasErrors(['inicio']);
        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation, [
            'inicio' => '2027-03-14T01:30',
            'fin' => '2027-03-14T03:30',
        ]))->assertSessionHasErrors(['inicio']);
        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation, [
            'inicio' => '2027-03-14T01:30',
            'fin' => '2027-03-14T03:00',
        ]))->assertSessionHasErrors(['inicio']);
        $this->assertDatabaseCount('reservas_espacio_comun', 0);
    }

    public function test_maximum_advance_notice_uses_local_calendar_days_across_dst(): void
    {
        Carbon::setTestNow('2026-10-31 14:00:00');
        [$administrator, $building, , $occupation] = $this->fixture();
        $building->update(['timezone' => 'America/New_York']);
        $space = $this->createSpace($administrator, $building);
        $this->addRule($administrator, $building, $space, $this->ruleData([
            'vigente_desde' => '2026-10-31',
            'antelacion_minima_minutos' => 0,
            'antelacion_maxima_dias' => 1,
        ]));

        $this->actingAs($administrator)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation, [
            'inicio' => '2026-11-01T10:00',
            'fin' => '2026-11-01T11:00',
        ]))->assertSessionHasNoErrors();
        $reservation = ReservaEspacioComunEloquentModel::query()->sole();
        $this->assertSame('2026-11-01 15:00:00', $reservation->inicio_at?->format('Y-m-d H:i:s'));
    }

    public function test_reservation_date_filters_use_each_building_local_day(): void
    {
        [$administrator, $building, , $occupation] = $this->fixture();
        $building->update(['timezone' => 'America/New_York']);
        $space = $this->createSpace($administrator, $building);
        $this->addRule($administrator, $building, $space, $this->ruleData([
            'horarios' => collect(range(1, 7))->map(static fn (int $day): array => ['dia_semana' => $day, 'hora_inicio' => '00:00', 'hora_fin' => '04:00'])->all(),
        ]));
        $this->createReservation($administrator, $building, $space, $occupation, ['inicio' => '2026-10-12T00:30', 'fin' => '2026-10-12T01:00']);
        $expected = ReservaEspacioComunEloquentModel::query()->sole();
        $this->createReservation($administrator, $building, $space, $occupation, ['inicio' => '2026-10-13T00:30', 'fin' => '2026-10-13T01:00']);

        $this->actingAs($administrator)->get(route('reservas-areas-comunes.index', [
            'edificio_id' => $building->id,
            'desde' => '2026-10-12',
            'hasta' => '2026-10-12',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('reservas.data', 1)
            ->where('reservas.data.0.id', $expected->id)
            ->where('reservas.data.0.inicioLocal', '2026-10-12T00:30')
            ->where('reservas.data.0.reglaSnapshot.timezone', 'America/New_York'));
    }

    public function test_space_agenda_only_contains_future_pending_or_confirmed_reservations(): void
    {
        [$administrator, $building, , $occupation] = $this->fixture();
        $space = $this->createSpace($administrator, $building);
        $this->addRule($administrator, $building, $space, $this->ruleData(['requiere_aprobacion' => true]));
        $this->createReservation($administrator, $building, $space, $occupation, ['inicio' => '2026-10-12T10:00', 'fin' => '2026-10-12T11:00']);
        $this->createReservation($administrator, $building, $space, $occupation, ['inicio' => '2026-10-12T12:00', 'fin' => '2026-10-12T13:00']);
        $this->createReservation($administrator, $building, $space, $occupation, ['inicio' => '2026-10-12T14:00', 'fin' => '2026-10-12T15:00']);
        [$rejected, $cancelled, $pending] = ReservaEspacioComunEloquentModel::query()->orderBy('inicio_at')->get()->all();
        $this->actingAs($administrator)->patch(route('reservas-areas-comunes.reject', [$building, $rejected]), ['motivo' => 'No disponible por mantenimiento.'])->assertSessionHasNoErrors();
        $this->actingAs($administrator)->patch(route('reservas-areas-comunes.cancel', [$building, $cancelled]), ['motivo' => 'Solicitud retirada por el residente.'])->assertSessionHasNoErrors();

        $this->actingAs($administrator)->get(route('areas-comunes.show', [$building, $space]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('espacio.reservas', 1)
                ->where('espacio.reservas.0.id', $pending->id)
                ->where('espacio.reservas.0.estado', 'pendiente'));
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel, DepartamentoEloquentModel, DepartamentoResidenteEloquentModel} */
    private function fixture(): array
    {
        $administrator = UserEloquentModel::factory()->create();
        $building = $this->buildingFor($administrator);
        $department = $this->departmentFor($building, 'A-101');

        return [$administrator, $building, $department, $this->occupantFor($building, $department, (string) random_int(1700000000, 1799999999))];
    }

    private function buildingFor(UserEloquentModel $administrator): EdificioEloquentModel
    {
        $number = DB::table('edificios')->count() + 1;
        $created = app(CreateEdificioAction::class)->execute([
            'nombre' => 'Edificio Áreas '.$number,
            'ruc' => str_pad((string) $number, 13, '0', STR_PAD_LEFT),
            'direccion' => 'Dirección '.$number,
            'ciudad' => 'Quito',
            'timezone' => 'America/Guayaquil',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $administrator->id);

        return EdificioEloquentModel::query()->findOrFail($created->id());
    }

    private function departmentFor(EdificioEloquentModel $building, string $code): DepartamentoEloquentModel
    {
        $tower = $building->torres()->sole();
        $floor = PisoEloquentModel::query()->where('edificio_id', $building->id)->first() ?? PisoEloquentModel::query()->forceCreate([
            'edificio_id' => $building->id, 'torre_id' => $tower->id, 'numero' => '1', 'nombre' => 'Primer piso', 'orden' => 1, 'estado' => 'activo',
        ]);

        return DepartamentoEloquentModel::query()->forceCreate([
            'edificio_id' => $building->id, 'piso_id' => $floor->id, 'codigo' => $code,
            'nombre' => 'Departamento '.$code, 'alicuota' => '5.000000', 'estado' => 'activo',
        ]);
    }

    private function occupantFor(EdificioEloquentModel $building, DepartamentoEloquentModel $department, string $identification, bool $activeOccupation = true, string $startDate = '2026-01-01'): DepartamentoResidenteEloquentModel
    {
        $thirdParty = TerceroEloquentModel::query()->forceCreate([
            'tipo_persona' => 'persona_natural', 'nombres' => 'Residente', 'apellidos' => $identification,
            'razon_social' => null, 'tipo_identificacion' => 'cedula', 'identificacion' => $identification,
            'telefono' => null, 'celular' => null, 'correo' => null, 'direccion' => 'Quito',
        ]);
        $resident = ResidenteEloquentModel::query()->forceCreate(['tercero_id' => $thirdParty->id, 'estado' => 'activo', 'observaciones' => null]);
        $resident->edificios()->attach($building->id);

        return DepartamentoResidenteEloquentModel::query()->forceCreate([
            'edificio_id' => $building->id, 'departamento_id' => $department->id, 'residente_id' => $resident->id,
            'nombre_residente' => 'Residente '.$identification, 'tipo_identificacion_snapshot' => 'cedula',
            'identificacion_snapshot' => $identification, 'tipo_ocupacion' => 'arrendatario',
            'fecha_inicio' => $startDate, 'fecha_fin' => $activeOccupation ? null : '2026-10-01',
            'estado' => $activeOccupation ? 'activa' : 'finalizada', 'observaciones' => null,
        ]);
    }

    private function createSpace(UserEloquentModel $actor, EdificioEloquentModel $building, array $overrides = []): EspacioComunEloquentModel
    {
        $before = EspacioComunEloquentModel::query()->pluck('id');
        $this->actingAs($actor)->post(route('areas-comunes.store', $building), $this->spaceData($overrides))->assertSessionHasNoErrors();

        return EspacioComunEloquentModel::query()->whereNotIn('id', $before)->sole();
    }

    private function addRule(UserEloquentModel $actor, EdificioEloquentModel $building, EspacioComunEloquentModel $space, array $data): void
    {
        $this->actingAs($actor)->post(route('areas-comunes.rules.store', [$building, $space]), $data)->assertSessionHasNoErrors();
    }

    private function createReservation(UserEloquentModel $actor, EdificioEloquentModel $building, EspacioComunEloquentModel $space, DepartamentoResidenteEloquentModel $occupation, array $overrides = []): void
    {
        $this->actingAs($actor)->post(route('reservas-areas-comunes.store', [$building, $space]), $this->reservationData($occupation, $overrides))->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function spaceData(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'SALON-SOCIAL', 'nombre' => 'Salón social', 'descripcion' => 'Espacio para reuniones.',
            'capacidad' => 50, 'torre_id' => null, 'piso_id' => null, 'ubicacion_detalle' => 'Planta baja',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function spaceUpdateData(EspacioComunEloquentModel $space, array $overrides = []): array
    {
        return [...$this->spaceData($overrides), 'updated_at' => $space->fresh()->updated_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function ruleData(array $overrides = []): array
    {
        return array_merge([
            'vigente_desde' => '2026-10-08', 'requiere_aprobacion' => false,
            'duracion_minima_minutos' => 30, 'duracion_maxima_minutos' => 180,
            'antelacion_minima_minutos' => 60, 'antelacion_maxima_dias' => 180,
            'buffer_antes_minutos' => 0, 'buffer_despues_minutos' => 0,
            'aforo_maximo' => 50, 'reglamento' => 'Cuidar el espacio.',
            'horarios' => collect(range(1, 7))->map(static fn (int $day): array => ['dia_semana' => $day, 'hora_inicio' => '08:00', 'hora_fin' => '22:00'])->all(),
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function reservationData(DepartamentoResidenteEloquentModel $occupation, array $overrides = []): array
    {
        return array_merge([
            'ocupacion_id' => $occupation->id, 'inicio' => '2026-10-12T10:00', 'fin' => '2026-10-12T11:00',
            'cantidad_asistentes' => 20, 'observaciones' => 'Reunión familiar.',
        ], $overrides);
    }

    private function addMember(EdificioEloquentModel $building, UserEloquentModel $member, RolEdificio $role, UserEloquentModel $actor): void
    {
        $building->usuarios()->attach($member->id, ['creado_por_user_id' => $actor->id]);
        DB::table('edificio_usuario_roles')->insert([
            'edificio_id' => $building->id, 'user_id' => $member->id, 'rol_codigo' => $role->value,
            'asignado_por_user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('La base permitió una mutación protegida de Áreas comunes.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
