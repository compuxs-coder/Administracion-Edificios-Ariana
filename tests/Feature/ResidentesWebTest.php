<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Models\TerceroEloquentModel;
use Tests\TestCase;

final class ResidentesWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-31 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_resident_routes_require_authentication_and_have_no_destructive_routes(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $residente = $this->makeResident($edificio, '1710000001');

        $this->get(route('residentes.index'))->assertRedirect(route('login'));
        $this->get(route('residentes.create'))->assertRedirect(route('login'));
        $this->get(route('residentes.show', $residente))->assertRedirect(route('login'));
        $this->get(route('residentes.edit', $residente))->assertRedirect(route('login'));
        $this->post(route('residentes.store', $edificio), $this->residentData())->assertRedirect(route('login'));
        $this->put(route('residentes.update', $residente), $this->residentData())->assertRedirect(route('login'));
        $this->patch(route('residentes.estado', $residente), ['estado' => 'inactivo'])->assertRedirect(route('login'));
        $this->post(route('departamentos.residentes.assign', [$edificio, $departamento->id]), [
            'residente_id' => $residente->id,
            'tipo_ocupacion' => 'arrendatario',
            'fecha_inicio' => '2026-01-01',
        ])->assertRedirect(route('login'));
        $this->patch(route('departamentos.residentes.finalize', [$edificio, $departamento->id, Str::uuid()]), [
            'fecha_fin' => '2026-02-01',
        ])->assertRedirect(route('login'));

        $this->assertFalse(Route::has('residentes.destroy'));
        $this->assertFalse(Route::has('departamentos.residentes.destroy'));
        $this->assertDatabaseCount('departamento_residentes', 0);
        $this->assertNotNull($user->id);
    }

    public function test_user_creates_lists_filters_shows_updates_and_changes_resident_status(): void
    {
        [$user, $edificio] = $this->buildingFixture();

        $this->actingAs($user)
            ->post(route('residentes.store', $edificio), $this->residentData([
                'nombres' => '',
                'identificacion' => '',
                'correo' => 'invalido',
            ]))
            ->assertSessionHasErrors(['nombres', 'identificacion', 'correo']);

        $response = $this->actingAs($user)
            ->post(route('residentes.store', $edificio), $this->residentData())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $residente = ResidenteEloquentModel::query()->with('tercero')->sole();
        $response->assertRedirect(route('residentes.show', $residente));

        $this->assertDatabaseHas('terceros', [
            'id' => $residente->tercero_id,
            'tipo_persona' => 'persona_natural',
            'identificacion' => '1712345678',
        ]);
        $this->assertDatabaseHas('residente_edificio', [
            'residente_id' => $residente->id,
            'edificio_id' => $edificio->id,
        ]);

        $this->actingAs($user)
            ->get(route('residentes.index', [
                'buscar' => 'Ana María',
                'estado' => 'activo',
                'edificio_id' => $edificio->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Residente/index', false)
                ->has('residentes.data', 1)
                ->where('residentes.data.0.id', $residente->id)
                ->where('residentes.data.0.terceroId', $residente->tercero_id)
                ->where('residentes.data.0.nombre', 'Ana María Torres López')
                ->where('residentes.meta.perPage', 15));

        $this->actingAs($user)
            ->get(route('residentes.show', $residente))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Residente/show', false)
                ->where('residente.identificacion', '1712345678')
                ->has('residente.ocupacionesActuales', 0)
                ->has('residente.historialOcupaciones', 0));

        $this->actingAs($user)
            ->put(route('residentes.update', $residente), $this->residentData([
                'nombres' => 'Ana Lucía',
                'telefono' => '022000000',
                'observaciones' => 'Contacto principal',
            ]))
            ->assertRedirect(route('residentes.show', $residente));
        $this->assertDatabaseHas('terceros', [
            'id' => $residente->tercero_id,
            'nombres' => 'Ana Lucía',
            'telefono' => '022000000',
        ]);
        $this->assertDatabaseHas('residentes', [
            'id' => $residente->id,
            'observaciones' => 'Contacto principal',
        ]);

        $this->actingAs($user)
            ->patch(route('residentes.estado', $residente), ['estado' => 'inactivo'])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->patch(route('residentes.estado', $residente), ['estado' => 'activo'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('residentes', ['id' => $residente->id, 'estado' => 'activo']);
    }

    public function test_identification_is_mandatory_globally_unique_and_visible_owner_identity_is_reused(): void
    {
        [$user, $edificio] = $this->buildingFixture();
        $propietario = $this->makeOwner($edificio, '1713000001');
        $terceroId = $propietario->tercero_id;

        $this->actingAs($user)
            ->post(route('residentes.store', $edificio), $this->residentData([
                'nombres' => 'Juan',
                'apellidos' => 'Pérez',
                'identificacion' => '1713000001',
            ]))
            ->assertSessionHasNoErrors();
        $residente = ResidenteEloquentModel::query()->sole();

        $this->assertSame($terceroId, $residente->tercero_id);
        $this->assertDatabaseCount('terceros', 1);
        $this->assertDatabaseHas('propietarios', [
            'id' => $propietario->id,
            'correo' => 'ana@example.test',
            'direccion' => 'Av. República 100',
        ]);
        $this->actingAs($user)
            ->post(route('residentes.store', $edificio), $this->residentData([
                'identificacion' => '1713000001',
            ]))
            ->assertSessionHasErrors('identificacion');

        $this->actingAs($user)
            ->put(route('residentes.update', $residente), $this->residentData([
                'nombres' => 'Juan Carlos',
                'apellidos' => 'Pérez',
                'identificacion' => '1713000001',
                'celular' => '0999999999',
            ]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('propietarios', [
            'id' => $propietario->id,
            'nombres' => 'Juan Carlos',
            'celular' => '0999999999',
        ]);

        $this->actingAs($user)
            ->put(route('propietarios.update', $propietario), $this->ownerData([
                'nombres' => 'Juan desde propietario',
            ]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('terceros', [
            'id' => $terceroId,
            'nombres' => 'Juan desde propietario',
        ]);

        $this->actingAs($user)
            ->put(route('propietarios.update', $propietario), $this->ownerData([
                'tipo_persona' => 'persona_juridica',
                'nombres' => null,
                'apellidos' => null,
                'razon_social' => 'Empresa no permitida',
                'tipo_identificacion' => 'ruc',
                'identificacion' => '1790000000001',
            ]))
            ->assertSessionHasErrors('tipoPersona');
        $this->assertDatabaseHas('terceros', ['id' => $terceroId, 'tipo_persona' => 'persona_natural']);

        try {
            DB::table('propietarios')->where('id', $propietario->id)->update(['tercero_id' => null]);
            $this->fail('La base permitió desvincular un propietario de su identidad global.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $residentOnly = $this->makeResident($edificio, '1713000002');
        $this->actingAs($user)
            ->post(route('propietarios.store', $edificio), $this->ownerData([
                'identificacion' => '1713000002',
            ]))
            ->assertSessionHasErrors('identificacion');
        $this->actingAs($user)
            ->post(route('propietarios.store', $edificio), $this->ownerData([
                'nombres' => 'Ana María',
                'apellidos' => 'Torres López',
                'identificacion' => '1713000002',
                'telefono' => '022345678',
                'celular' => '0991234567',
                'correo' => 'ana1713000002@example.test',
                'direccion' => 'Quito',
            ]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('propietarios', ['tercero_id' => $residentOnly->tercero_id]);

        $replacementIdentity = TerceroEloquentModel::query()->forceCreate([
            'tipo_persona' => 'persona_natural',
            'nombres' => 'Identidad',
            'apellidos' => 'Alternativa',
            'razon_social' => null,
            'tipo_identificacion' => 'otro',
            'identificacion' => 'IDENTIDAD-ALTERNATIVA',
        ]);
        foreach ([$propietario, $residente] as $profile) {
            try {
                DB::transaction(static fn () => $profile->update(['tercero_id' => $replacementIdentity->id]));
                $this->fail('La base permitió reemplazar la identidad global de un perfil existente.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame($terceroId, $propietario->refresh()->tercero_id);
        $this->assertSame($terceroId, $residente->refresh()->tercero_id);
    }

    public function test_department_allows_multiple_current_residents_and_resident_allows_multiple_simultaneous_units(): void
    {
        [$user, $firstBuilding, $firstDepartment] = $this->buildingFixture();
        $secondDepartment = $this->makeDepartment($firstBuilding, 'A-102');
        $secondBuilding = $this->createBuilding($user);
        $thirdDepartment = $this->makeDepartment($secondBuilding, 'B-201');
        $ana = $this->makeResident($firstBuilding, '1714000001');
        $luis = $this->makeResident($firstBuilding, '1714000002', ['nombres' => 'Luis', 'apellidos' => 'Vega']);

        $this->assignResident($user, $firstBuilding, $firstDepartment, $ana, 'arrendatario');
        $this->assignResident($user, $firstBuilding, $firstDepartment, $luis, 'otro');
        $this->assignResident($user, $firstBuilding, $secondDepartment, $ana, 'otro');
        $this->assignResident($user, $secondBuilding, $thirdDepartment, $ana, 'arrendatario');

        $this->assertSame(2, DepartamentoResidenteEloquentModel::query()
            ->where('departamento_id', $firstDepartment->id)->where('estado', 'activa')->count());
        $this->assertSame(3, DepartamentoResidenteEloquentModel::query()
            ->where('residente_id', $ana->id)->where('estado', 'activa')->count());
        $this->assertDatabaseHas('residente_edificio', [
            'residente_id' => $ana->id,
            'edificio_id' => $secondBuilding->id,
        ]);

        $this->actingAs($user)
            ->get(route('departamentos.show', [$firstBuilding, $firstDepartment->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('ocupacion.actuales', 2)
                ->has('ocupacion.historial', 0)
                ->has('ocupacion.opciones', 2));
    }

    public function test_owner_occupant_requires_same_third_party_ownership_effective_on_start(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $propietario = $this->makeOwner($edificio, '1715000001');
        $residente = $this->makeResidentFromTercero($edificio, $propietario->tercero);
        DepartamentoPropietarioEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamento->id,
            'propietario_id' => $propietario->id,
            'nombre_propietario' => 'Juan Pérez',
            'tipo_identificacion_snapshot' => 'cedula',
            'identificacion_snapshot' => '1715000001',
            'porcentaje' => '100.000000',
            'fecha_inicio' => '2026-03-01',
            'fecha_fin' => null,
            'estado' => 'activa',
        ]);

        $this->actingAs($user)
            ->post(route('departamentos.residentes.assign', [$edificio, $departamento->id]), [
                'residente_id' => $residente->id,
                'tipo_ocupacion' => 'propietario_ocupante',
                'fecha_inicio' => '2026-02-01',
            ])
            ->assertSessionHasErrors('tipoOcupacion');

        $this->actingAs($user)
            ->post(route('departamentos.residentes.assign', [$edificio, $departamento->id]), [
                'residente_id' => $residente->id,
                'tipo_ocupacion' => 'propietario_ocupante',
                'fecha_inicio' => '2026-03-01',
            ])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('departamento_residentes', [
            'residente_id' => $residente->id,
            'tipo_ocupacion' => 'propietario_ocupante',
        ]);
    }

    public function test_finalization_uses_half_open_intervals_preserves_snapshot_and_rejects_overlap(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $residente = $this->makeResident($edificio, '1716000001');
        $this->assignResident($user, $edificio, $departamento, $residente, 'arrendatario', '2026-01-01');
        $ocupacion = DepartamentoResidenteEloquentModel::query()->sole();

        $this->actingAs($user)
            ->patch(route('departamentos.residentes.finalize', [$edificio, $departamento->id, $ocupacion->id]), [
                'fecha_fin' => '2026-01-01',
            ])
            ->assertSessionHasErrors('fechaFin');
        $this->actingAs($user)
            ->patch(route('departamentos.residentes.finalize', [$edificio, $departamento->id, $ocupacion->id]), [
                'fecha_fin' => '2026-02-01',
                'observaciones' => 'Salida registrada',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->put(route('residentes.update', $residente), $this->residentData([
                'nombres' => 'Ana Actualizada',
                'identificacion' => '1716000001',
            ]))
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->get(route('departamentos.show', [$edificio, $departamento->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('ocupacion.historial.0.nombre', 'Ana María Torres López')
                ->where('ocupacion.historial.0.estado', 'finalizada'));

        $this->actingAs($user)
            ->post(route('departamentos.residentes.assign', [$edificio, $departamento->id]), [
                'residente_id' => $residente->id,
                'tipo_ocupacion' => 'otro',
                'fecha_inicio' => '2026-01-15',
            ])
            ->assertSessionHasErrors('residenteId');
        $this->assignResident($user, $edificio, $departamento, $residente, 'otro', '2026-02-01');

        $this->assertDatabaseCount('departamento_residentes', 2);
        $this->assertSame(1, DepartamentoResidenteEloquentModel::query()->where('estado', 'finalizada')->count());
    }

    public function test_ownership_changes_cannot_invalidate_owner_occupant_history(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $propietario = $this->makeOwner($edificio, '1716500001');
        $replacement = $this->makeOwner($edificio, '1716500002');
        $residente = $this->makeResidentFromTercero($edificio, $propietario->tercero);
        $titularidad = DepartamentoPropietarioEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamento->id,
            'propietario_id' => $propietario->id,
            'nombre_propietario' => 'Juan Pérez',
            'tipo_identificacion_snapshot' => 'cedula',
            'identificacion_snapshot' => '1716500001',
            'porcentaje' => '100.000000',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => null,
            'estado' => 'activa',
        ]);
        $this->assignResident(
            $user,
            $edificio,
            $departamento,
            $residente,
            'propietario_ocupante',
            '2026-03-01',
        );

        $this->actingAs($user)
            ->patch(route('departamentos.propietarios.finalize', [$edificio, $departamento->id, $titularidad->id]), [
                'fecha_fin' => '2026-02-01',
            ])
            ->assertSessionHasErrors('fechaFin');
        $this->actingAs($user)
            ->post(route('departamentos.propietarios.transfer', [$edificio, $departamento->id]), [
                'fecha_transferencia' => '2026-02-01',
                'propietarios' => [[
                    'propietario_id' => $replacement->id,
                    'porcentaje' => '100.000000',
                ]],
            ])
            ->assertSessionHasErrors('fechaTransferencia');

        $ocupacion = DepartamentoResidenteEloquentModel::query()->sole();
        $this->actingAs($user)
            ->patch(route('departamentos.residentes.finalize', [$edificio, $departamento->id, $ocupacion->id]), [
                'fecha_fin' => '2026-04-01',
            ])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->patch(route('departamentos.propietarios.finalize', [$edificio, $departamento->id, $titularidad->id]), [
                'fecha_fin' => '2026-04-01',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('departamento_residentes', [
            'id' => $ocupacion->id,
            'estado' => 'finalizada',
        ]);
        $this->assertDatabaseHas('departamento_propietarios', [
            'id' => $titularidad->id,
            'estado' => 'finalizada',
        ]);
    }

    public function test_http_rejects_future_dates_and_inactive_entities_and_blocks_inactivation_with_active_occupancy(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $residente = $this->makeResident($edificio, '1717000001');

        $this->actingAs($user)
            ->post(route('departamentos.residentes.assign', [$edificio, $departamento->id]), [
                'residente_id' => $residente->id,
                'tipo_ocupacion' => 'otro',
                'fecha_inicio' => '2026-09-01',
            ])
            ->assertSessionHasErrors('fechaInicio');
        $this->assignResident($user, $edificio, $departamento, $residente, 'arrendatario');
        $ocupacion = DepartamentoResidenteEloquentModel::query()->sole();

        $this->actingAs($user)
            ->patch(route('residentes.estado', $residente), ['estado' => 'inactivo'])
            ->assertSessionHasErrors('estado');
        $this->actingAs($user)
            ->patch(route('departamentos.estado', [$edificio, $departamento->id]), ['estado' => 'inactivo'])
            ->assertSessionHasErrors('estado');
        $this->actingAs($user)
            ->patch(route('edificios.estado', $edificio), ['estado' => 'inactivo'])
            ->assertSessionHasErrors('estado');
        $this->assertDatabaseHas('edificios', ['id' => $edificio->id, 'estado' => 'activo']);
        $this->actingAs($user)
            ->patch(route('departamentos.residentes.finalize', [$edificio, $departamento->id, $ocupacion->id]), [
                'fecha_fin' => '2026-09-01',
            ])
            ->assertSessionHasErrors('fechaFin');

        $this->actingAs($user)
            ->patch(route('departamentos.residentes.finalize', [$edificio, $departamento->id, $ocupacion->id]), [
                'fecha_fin' => '2026-02-01',
            ])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->patch(route('residentes.estado', $residente), ['estado' => 'inactivo'])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->post(route('departamentos.residentes.assign', [$edificio, $departamento->id]), [
                'residente_id' => $residente->id,
                'tipo_ocupacion' => 'otro',
                'fecha_inicio' => '2026-03-01',
            ])
            ->assertSessionHasErrors('residenteId');
        $this->actingAs($user)
            ->patch(route('departamentos.estado', [$edificio, $departamento->id]), ['estado' => 'inactivo'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->patch(route('residentes.estado', $residente), ['estado' => 'activo'])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->patch(route('departamentos.estado', [$edificio, $departamento->id]), ['estado' => 'activo'])
            ->assertSessionHasNoErrors();
        $edificio->update(['estado' => 'inactivo']);
        $this->actingAs($user)
            ->post(route('departamentos.residentes.assign', [$edificio, $departamento->id]), [
                'residente_id' => $residente->id,
                'tipo_ocupacion' => 'otro',
                'fecha_inicio' => '2026-03-01',
            ])
            ->assertNotFound();
    }

    public function test_visibility_nested_scoping_and_global_edit_authorization_do_not_disclose_other_buildings(): void
    {
        [$firstUser, $firstBuilding, $firstDepartment] = $this->buildingFixture();
        [$secondUser, $secondBuilding, $secondDepartment] = $this->buildingFixture();
        $outsider = UserEloquentModel::factory()->create();
        $residente = $this->makeResident($firstBuilding, '1718000001');
        $residente->edificios()->attach($secondBuilding->id);

        $this->actingAs($firstUser)->get(route('residentes.show', $residente))->assertOk();
        $this->actingAs($secondUser)->get(route('residentes.show', $residente))->assertOk();
        $this->actingAs($firstUser)->get(route('residentes.edit', $residente))->assertForbidden();
        $this->actingAs($secondUser)
            ->put(route('residentes.update', $residente), $this->residentData(['identificacion' => '1718000001']))
            ->assertForbidden();
        $this->actingAs($outsider)->get(route('residentes.show', $residente))->assertForbidden();
        $this->actingAs($outsider)
            ->get(route('residentes.index'))
            ->assertInertia(fn (Assert $page) => $page->has('residentes.data', 0));

        $this->assignResident($firstUser, $firstBuilding, $firstDepartment, $residente, 'otro');
        $ocupacion = DepartamentoResidenteEloquentModel::query()->sole();
        $this->actingAs($firstUser)
            ->patch(route('departamentos.residentes.finalize', [$firstBuilding, $secondDepartment->id, $ocupacion->id]), [
                'fecha_fin' => '2026-02-01',
            ])
            ->assertNotFound();
        $this->actingAs($secondUser)
            ->patch(route('departamentos.residentes.finalize', [$secondBuilding, $secondDepartment->id, $ocupacion->id]), [
                'fecha_fin' => '2026-02-01',
            ])
            ->assertNotFound();

        $privateOwner = $this->makeOwner($firstBuilding, '1718000002');
        $this->actingAs($secondUser)
            ->post(route('residentes.store', $secondBuilding), $this->residentData([
                'nombres' => 'Juan',
                'apellidos' => 'Pérez',
                'identificacion' => $privateOwner->identificacion,
            ]))
            ->assertSessionHasErrors('identificacion');
    }

    public function test_database_guards_cross_building_links_and_immutable_resident_history(): void
    {
        [$user, $firstBuilding, $firstDepartment] = $this->buildingFixture();
        [, $secondBuilding, $secondDepartment] = $this->buildingFixture();
        $residente = $this->makeResident($firstBuilding, '1719000001');

        try {
            DB::transaction(static fn () => DepartamentoResidenteEloquentModel::query()->forceCreate([
                'edificio_id' => $firstBuilding->id,
                'departamento_id' => $secondDepartment->id,
                'residente_id' => $residente->id,
                'nombre_residente' => 'Ana María Torres López',
                'tipo_identificacion_snapshot' => 'cedula',
                'identificacion_snapshot' => '1719000001',
                'tipo_ocupacion' => 'otro',
                'fecha_inicio' => '2026-01-01',
                'fecha_fin' => null,
                'estado' => 'activa',
            ]));
            $this->fail('La base permitió una ocupación con un departamento de otro edificio.');
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            $this->assertTrue(
                str_contains($message, 'foreign key') || str_contains($message, 'requiere edificio'),
            );
        }

        $this->assignResident($user, $firstBuilding, $firstDepartment, $residente, 'otro');
        $ocupacion = DepartamentoResidenteEloquentModel::query()->sole();

        try {
            DB::transaction(static fn () => $ocupacion->update(['tipo_ocupacion' => 'arrendatario']));
            $this->fail('La base permitió reescribir el tipo de una ocupación activa.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->actingAs($user)
            ->patch(route('departamentos.residentes.finalize', [$firstBuilding, $firstDepartment->id, $ocupacion->id]), [
                'fecha_fin' => '2026-02-01',
            ])
            ->assertSessionHasNoErrors();

        foreach ([
            static fn () => $ocupacion->refresh()->update(['observaciones' => 'Cambio prohibido']),
            static fn () => $ocupacion->refresh()->delete(),
            static fn () => $residente->refresh()->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('La base permitió modificar o eliminar historial protegido.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseHas('departamento_residentes', [
            'id' => $ocupacion->id,
            'estado' => 'finalizada',
            'observaciones' => null,
        ]);
        $this->assertDatabaseHas('residentes', ['id' => $residente->id]);
        $this->assertNotSame($firstBuilding->id, $secondBuilding->id);
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel, DepartamentoEloquentModel} */
    private function buildingFixture(): array
    {
        $user = UserEloquentModel::factory()->create();
        $edificio = $this->createBuilding($user);

        return [$user, $edificio, $this->makeDepartment($edificio, 'A-101')];
    }

    private function createBuilding(UserEloquentModel $user): EdificioEloquentModel
    {
        /** @var CreateEdificioAction $action */
        $action = $this->app->make(CreateEdificioAction::class);
        $created = $action->execute([
            'nombre' => 'Edificio '.Str::upper(Str::random(8)),
            'ruc' => Str::upper(Str::random(13)),
            'direccion' => 'Av. Principal 123',
            'ciudad' => 'Quito',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $user->id);

        return EdificioEloquentModel::query()->findOrFail($created->id());
    }

    private function makeDepartment(EdificioEloquentModel $edificio, string $codigo): DepartamentoEloquentModel
    {
        $torre = $edificio->torres()->sole();
        $piso = $edificio->pisos()->first() ?? PisoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'torre_id' => $torre->id,
            'numero' => '1',
            'nombre' => 'Primer piso',
            'orden' => 1,
            'estado' => 'activo',
        ]);

        return DepartamentoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'piso_id' => $piso->id,
            'codigo' => $codigo,
            'nombre' => 'Departamento '.$codigo,
            'alicuota' => '5.000000',
            'estado' => 'activo',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeResident(
        EdificioEloquentModel $edificio,
        string $identificacion,
        array $overrides = [],
    ): ResidenteEloquentModel {
        $tercero = TerceroEloquentModel::query()->forceCreate(array_merge([
            'tipo_persona' => 'persona_natural',
            'nombres' => 'Ana María',
            'apellidos' => 'Torres López',
            'razon_social' => null,
            'tipo_identificacion' => 'cedula',
            'identificacion' => $identificacion,
            'telefono' => '022345678',
            'celular' => '0991234567',
            'correo' => 'ana'.$identificacion.'@example.test',
            'direccion' => 'Quito',
        ], $overrides));

        return $this->makeResidentFromTercero($edificio, $tercero);
    }

    private function makeResidentFromTercero(
        EdificioEloquentModel $edificio,
        TerceroEloquentModel $tercero,
    ): ResidenteEloquentModel {
        $residente = ResidenteEloquentModel::query()->forceCreate([
            'tercero_id' => $tercero->id,
            'estado' => 'activo',
            'observaciones' => null,
        ]);
        $residente->edificios()->attach($edificio->id);

        return $residente;
    }

    private function makeOwner(EdificioEloquentModel $edificio, string $identificacion): PropietarioEloquentModel
    {
        $propietario = PropietarioEloquentModel::query()->forceCreate([
            'tipo_persona' => 'persona_natural',
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'razon_social' => null,
            'tipo_identificacion' => 'cedula',
            'identificacion' => $identificacion,
            'telefono' => '022345678',
            'celular' => '0991234567',
            'correo' => 'juan'.$identificacion.'@example.test',
            'direccion' => 'Quito',
            'estado' => 'activo',
            'observaciones' => null,
        ]);
        $propietario->edificios()->attach($edificio->id);

        return $propietario;
    }

    private function assignResident(
        UserEloquentModel $user,
        EdificioEloquentModel $edificio,
        DepartamentoEloquentModel $departamento,
        ResidenteEloquentModel $residente,
        string $tipo,
        string $fechaInicio = '2026-01-01',
    ): void {
        $this->actingAs($user)
            ->post(route('departamentos.residentes.assign', [$edificio, $departamento->id]), [
                'residente_id' => $residente->id,
                'tipo_ocupacion' => $tipo,
                'fecha_inicio' => $fechaInicio,
            ])
            ->assertSessionHasNoErrors();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function residentData(array $overrides = []): array
    {
        return array_merge([
            'nombres' => 'Ana María',
            'apellidos' => 'Torres López',
            'tipo_identificacion' => 'cedula',
            'identificacion' => '1712345678',
            'telefono' => '022345678',
            'celular' => '0991234567',
            'correo' => 'ana@example.test',
            'direccion' => 'Av. República 100',
            'observaciones' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function ownerData(array $overrides = []): array
    {
        return array_merge([
            'tipo_persona' => 'persona_natural',
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'razon_social' => null,
            'tipo_identificacion' => 'cedula',
            'identificacion' => '1713000001',
            'telefono' => '022345678',
            'celular' => '0991234567',
            'correo' => 'juan@example.test',
            'direccion' => 'Quito',
            'observaciones' => null,
        ], $overrides);
    }
}
