<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Edificio\Infrastructure\Models\TorreEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;
use Src\Propiedad\Application\Actions\TransferPropiedadAction;
use Tests\TestCase;

final class PropietarioWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_routes_require_authentication(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $propietario = $this->createOwner($edificio, '1710000001');

        $this->get(route('propietarios.index'))->assertRedirect(route('login'));
        $this->get(route('propietarios.create'))->assertRedirect(route('login'));
        $this->get(route('propietarios.show', $propietario))->assertRedirect(route('login'));
        $this->get(route('propietarios.edit', $propietario))->assertRedirect(route('login'));
        $this->post(route('propietarios.store', $edificio), $this->ownerData())->assertRedirect(route('login'));
        $this->put(route('propietarios.update', $propietario), $this->ownerData())->assertRedirect(route('login'));
        $this->patch(route('propietarios.estado', $propietario), ['estado' => 'inactivo'])->assertRedirect(route('login'));
        $this->post(route('departamentos.propietarios.assign', [$edificio, $departamento->id]), [
            'propietario_id' => $propietario->id,
            'porcentaje' => '100.000000',
            'fecha_inicio' => '2026-01-01',
        ])->assertRedirect(route('login'));
        $this->patch(route('departamentos.propietarios.finalize', [$edificio, $departamento->id, Str::uuid()]), [
            'fecha_fin' => '2026-02-01',
        ])->assertRedirect(route('login'));
        $this->post(route('departamentos.propietarios.transfer', [$edificio, $departamento->id]), [
            'fecha_transferencia' => '2026-02-01',
            'propietarios' => [['propietario_id' => $propietario->id, 'porcentaje' => '100.000000']],
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('departamento_propietarios', 0);
        $this->assertNotNull($user->id);
    }

    public function test_user_creates_natural_and_legal_owners_in_an_assigned_building(): void
    {
        [$user, $edificio] = $this->buildingFixture();

        $response = $this->actingAs($user)
            ->post(route('propietarios.store', $edificio), $this->ownerData())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $natural = PropietarioEloquentModel::query()->where('identificacion', '1712345678')->sole();
        $response->assertRedirect(route('propietarios.show', $natural->id));
        $this->assertDatabaseHas('propietario_edificio', [
            'propietario_id' => $natural->id,
            'edificio_id' => $edificio->id,
        ]);

        $this->actingAs($user)
            ->post(route('propietarios.store', $edificio), $this->ownerData([
                'tipo_persona' => 'persona_juridica',
                'nombres' => null,
                'apellidos' => null,
                'razon_social' => 'Inmobiliaria Ariana S.A.',
                'tipo_identificacion' => 'ruc',
                'identificacion' => '1791234567001',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('propietarios', [
            'razon_social' => 'Inmobiliaria Ariana S.A.',
            'nombres' => null,
            'estado' => 'activo',
        ]);
        $this->assertDatabaseHas('propietarios', [
            'id' => $natural->id,
            'telefono' => '022345678',
            'celular' => '0991234567',
            'correo' => 'ana@example.test',
            'direccion' => 'Av. República 100',
        ]);
        $this->assertDatabaseCount('propietarios', 2);

        $this->actingAs($user)
            ->get(route('propietarios.index', ['buscar' => '171234']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Propietario/index')
                ->has('propietarios.data', 1)
                ->where('propietarios.data.0.id', $natural->id));
    }

    public function test_owner_validation_rejects_invalid_data_and_duplicate_identification(): void
    {
        [$user, $edificio] = $this->buildingFixture();
        $this->createOwner($edificio, '1712345678');

        $this->actingAs($user)
            ->post(route('propietarios.store', $edificio), $this->ownerData([
                'nombres' => '',
                'apellidos' => '',
                'identificacion' => '1712345678',
                'correo' => 'correo-invalido',
            ]))
            ->assertSessionHasErrors(['nombres', 'apellidos', 'identificacion', 'correo']);

        $this->actingAs($user)
            ->post(route('propietarios.store', $edificio), $this->ownerData([
                'tipo_persona' => 'persona_juridica',
                'nombres' => null,
                'apellidos' => null,
                'razon_social' => '',
                'tipo_identificacion' => 'ruc',
                'identificacion' => '1790000000001',
            ]))
            ->assertSessionHasErrors('razonSocial');

        $this->assertDatabaseCount('propietarios', 1);
    }

    public function test_assigned_user_views_edits_and_changes_owner_status(): void
    {
        [$user, $edificio] = $this->buildingFixture();
        $propietario = $this->createOwner($edificio, '1711111111');

        $this->actingAs($user)
            ->get(route('propietarios.show', $propietario))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Propietario/show')
                ->where('propietario.nombre', 'Juan Pérez'));

        $this->actingAs($user)
            ->put(route('propietarios.update', $propietario), $this->ownerData([
                'tipo_persona' => 'persona_juridica',
                'nombres' => null,
                'apellidos' => null,
                'razon_social' => 'Grupo Pérez Cía. Ltda.',
                'tipo_identificacion' => 'ruc',
                'identificacion' => '1791111111001',
            ]))
            ->assertRedirect(route('propietarios.show', $propietario));

        $this->assertDatabaseHas('propietarios', [
            'id' => $propietario->id,
            'tipo_persona' => 'persona_juridica',
            'nombres' => null,
            'razon_social' => 'Grupo Pérez Cía. Ltda.',
        ]);

        $this->actingAs($user)
            ->patch(route('propietarios.estado', $propietario), ['estado' => 'inactivo'])
            ->assertRedirect();
        $this->actingAs($user)
            ->patch(route('propietarios.estado', $propietario), ['estado' => 'activo'])
            ->assertRedirect();

        $this->assertDatabaseHas('propietarios', ['id' => $propietario->id, 'estado' => 'activo']);
    }

    public function test_owner_is_assigned_to_department_and_visible_in_both_details(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $propietario = $this->createOwner($edificio, '1712222222');

        $this->actingAs($user)
            ->post(route('departamentos.propietarios.assign', [$edificio, $departamento->id]), [
                'propietario_id' => $propietario->id,
                'porcentaje' => '100.000000',
                'fecha_inicio' => '2026-01-01',
                'observaciones' => 'Compra inicial',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('departamento_propietarios', [
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamento->id,
            'propietario_id' => $propietario->id,
            'porcentaje' => 100,
            'estado' => 'activa',
            'fecha_fin' => null,
        ]);

        $this->actingAs($user)
            ->get(route('departamentos.show', [$edificio, $departamento->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Departamento/show')
                ->where('departamento.id', $departamento->id)
                ->has('propiedad.actuales', 1)
                ->where('propiedad.participacionActual', '100.000000'));

        $this->actingAs($user)
            ->get(route('propietarios.show', $propietario))
            ->assertInertia(fn (Assert $page) => $page
                ->has('propietario.propiedadesActuales', 1)
                ->where('propietario.propiedadesActuales.0.departamentoId', $departamento->id));
    }

    public function test_department_accepts_copropiedad_up_to_one_hundred_percent(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $juan = $this->createOwner($edificio, '1713000001');
        $maria = $this->createOwner($edificio, '1713000002', ['nombres' => 'María', 'apellidos' => 'López']);

        foreach ([[$juan, '50.000000'], [$maria, '50.000000']] as [$propietario, $porcentaje]) {
            $this->actingAs($user)
                ->post(route('departamentos.propietarios.assign', [$edificio, $departamento->id]), [
                    'propietario_id' => $propietario->id,
                    'porcentaje' => $porcentaje,
                    'fecha_inicio' => '2026-01-01',
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('departamento_propietarios', 2);
        $this->assertSame('100.000000', DepartamentoPropietarioEloquentModel::query()->sum('porcentaje') > 0
            ? number_format((float) DepartamentoPropietarioEloquentModel::query()->sum('porcentaje'), 6, '.', '')
            : '0.000000');
    }

    public function test_assignment_rejects_percentage_over_one_hundred_and_duplicate_active_owner(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $juan = $this->createOwner($edificio, '1714000001');
        $maria = $this->createOwner($edificio, '1714000002');

        $this->assign($user, $edificio, $departamento, $juan, '60.000000');

        $this->actingAs($user)
            ->post(route('departamentos.propietarios.assign', [$edificio, $departamento->id]), [
                'propietario_id' => $maria->id,
                'porcentaje' => '40.000001',
                'fecha_inicio' => '2026-01-01',
            ])
            ->assertSessionHasErrors('porcentaje');

        $this->actingAs($user)
            ->post(route('departamentos.propietarios.assign', [$edificio, $departamento->id]), [
                'propietario_id' => $juan->id,
                'porcentaje' => '10.000000',
                'fecha_inicio' => '2026-02-01',
            ])
            ->assertSessionHasErrors('propietarioId');

        $this->assertDatabaseCount('departamento_propietarios', 1);
    }

    public function test_finalization_preserves_history_and_allows_a_later_non_overlapping_ownership(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $propietario = $this->createOwner($edificio, '1715000001');
        $this->assign($user, $edificio, $departamento, $propietario, '100.000000');
        $titularidad = DepartamentoPropietarioEloquentModel::query()->sole();

        $this->actingAs($user)
            ->patch(route('departamentos.propietarios.finalize', [$edificio, $departamento->id, $titularidad->id]), [
                'fecha_fin' => '2026-01-01',
            ])
            ->assertSessionHasErrors('fechaFin');

        $this->assertDatabaseHas('departamento_propietarios', ['id' => $titularidad->id, 'estado' => 'activa']);

        $this->actingAs($user)
            ->patch(route('departamentos.propietarios.finalize', [$edificio, $departamento->id, $titularidad->id]), [
                'fecha_fin' => '2026-02-01',
                'observaciones' => 'Venta registrada',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('departamento_propietarios', [
            'id' => $titularidad->id,
            'estado' => 'finalizada',
        ]);
        $this->assertSame('2026-02-01', $titularidad->refresh()->fecha_fin->format('Y-m-d'));

        $this->actingAs($user)
            ->put(route('propietarios.update', $propietario), $this->ownerData([
                'nombres' => 'Juan Actualizado',
                'apellidos' => 'Pérez',
                'identificacion' => $propietario->identificacion,
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get(route('departamentos.show', [$edificio, $departamento->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('propiedad.historial.0.nombre', 'Juan Pérez'));

        $this->actingAs($user)
            ->post(route('departamentos.propietarios.assign', [$edificio, $departamento->id]), [
                'propietario_id' => $propietario->id,
                'porcentaje' => '100.000000',
                'fecha_inicio' => '2026-02-01',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('departamento_propietarios', 2);
        $this->assertSame(1, DepartamentoPropietarioEloquentModel::query()->where('estado', 'activa')->count());
        $this->assertFalse(Route::has('propietarios.destroy'));
        $this->assertFalse(Route::has('departamentos.propietarios.destroy'));
    }

    public function test_transfer_is_atomic_and_replaces_current_owners_with_copropietarios(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $anterior = $this->createOwner($edificio, '1716000001');
        $nuevo1 = $this->createOwner($edificio, '1716000002');
        $nuevo2 = $this->createOwner($edificio, '1716000003');
        $this->assign($user, $edificio, $departamento, $anterior, '100.000000');

        $invalidData = [
            'fecha_transferencia' => '2026-03-01',
            'propietarios' => [
                ['propietario_id' => $nuevo1->id, 'porcentaje' => '60.000000'],
                ['propietario_id' => $nuevo2->id, 'porcentaje' => '50.000000'],
            ],
            'observaciones' => 'Transferencia inválida',
        ];
        $this->actingAs($user)
            ->post(route('departamentos.propietarios.transfer', [$edificio, $departamento->id]), $invalidData)
            ->assertSessionHasErrors('propietarios');

        $this->assertDatabaseHas('departamento_propietarios', [
            'propietario_id' => $anterior->id,
            'estado' => 'activa',
            'fecha_fin' => null,
        ]);
        $this->assertDatabaseCount('departamento_propietarios', 1);

        $validData = $invalidData;
        $validData['propietarios'][0]['porcentaje'] = '50.000000';
        $validData['propietarios'][1]['propietario_id'] = strtoupper($nuevo2->id);
        $this->actingAs($user)
            ->post(route('departamentos.propietarios.transfer', [$edificio, $departamento->id]), $validData)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('departamento_propietarios', 3);
        $this->assertDatabaseHas('departamento_propietarios', [
            'propietario_id' => $anterior->id,
            'estado' => 'finalizada',
        ]);
        $this->assertSame(
            '2026-03-01',
            DepartamentoPropietarioEloquentModel::query()
                ->where('propietario_id', $anterior->id)
                ->sole()
                ->fecha_fin
                ->format('Y-m-d'),
        );
        $this->assertSame(2, DepartamentoPropietarioEloquentModel::query()->where('estado', 'activa')->count());
        $this->assertEqualsWithDelta(100, (float) DepartamentoPropietarioEloquentModel::query()
            ->where('estado', 'activa')->sum('porcentaje'), 0.000001);
    }

    public function test_transfer_rejects_malformed_or_incomplete_payloads_before_changing_history(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $anterior = $this->createOwner($edificio, '1716500001');
        $nuevo = $this->createOwner($edificio, '1716500002');
        $this->assign($user, $edificio, $departamento, $anterior, '100.000000');

        $this->actingAs($user)
            ->post(route('departamentos.propietarios.transfer', [$edificio, $departamento->id]), [
                'fecha_transferencia' => '2026-03-01',
                'propietarios' => [null],
            ])
            ->assertSessionHasErrors('propietarios.0');

        /** @var TransferPropiedadAction $action */
        $action = $this->app->make(TransferPropiedadAction::class);
        try {
            $action->execute($user->id, $edificio->id, $departamento->id, [
                'fecha_transferencia' => '2026-03-01',
                'propietarios' => [['propietario_id' => $nuevo->id, 'porcentaje' => '50.000000']],
            ]);
            $this->fail('La transferencia incompleta no fue rechazada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('propietarios', $exception->errors());
        }

        $this->assertDatabaseHas('departamento_propietarios', [
            'propietario_id' => $anterior->id,
            'estado' => 'activa',
            'fecha_fin' => null,
        ]);
        $this->assertDatabaseCount('departamento_propietarios', 1);
    }

    public function test_owner_with_active_property_cannot_be_inactivated(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $propietario = $this->createOwner($edificio, '1717000001');
        $this->assign($user, $edificio, $departamento, $propietario, '100.000000');

        $this->actingAs($user)
            ->patch(route('propietarios.estado', $propietario), ['estado' => 'inactivo'])
            ->assertSessionHasErrors('estado');

        $this->assertDatabaseHas('propietarios', ['id' => $propietario->id, 'estado' => 'activo']);
    }

    public function test_backdated_ownership_cannot_make_historical_participation_exceed_one_hundred(): void
    {
        [$user, $edificio, $departamento] = $this->buildingFixture();
        $anterior = $this->createOwner($edificio, '1717500001');
        $nuevo = $this->createOwner($edificio, '1717500002');
        $this->assign($user, $edificio, $departamento, $anterior, '100.000000');
        $titularidad = DepartamentoPropietarioEloquentModel::query()->sole();

        $this->actingAs($user)
            ->patch(route('departamentos.propietarios.finalize', [$edificio, $departamento->id, $titularidad->id]), [
                'fecha_fin' => '2026-06-01',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('departamentos.propietarios.assign', [$edificio, $departamento->id]), [
                'propietario_id' => $nuevo->id,
                'porcentaje' => '100.000000',
                'fecha_inicio' => '2026-03-01',
            ])
            ->assertSessionHasErrors('porcentaje');

        $this->assertDatabaseCount('departamento_propietarios', 1);
    }

    public function test_shared_owner_requires_access_to_all_linked_buildings_for_global_edits(): void
    {
        [$firstUser, $firstBuilding] = $this->buildingFixture();
        [$secondUser, $secondBuilding] = $this->buildingFixture();
        $propietario = $this->createOwner($firstBuilding, '1717600001');
        $propietario->edificios()->attach($secondBuilding->id);

        $this->actingAs($firstUser)->get(route('propietarios.show', $propietario))->assertOk();
        $this->actingAs($secondUser)->get(route('propietarios.show', $propietario))->assertOk();
        $this->actingAs($firstUser)->get(route('propietarios.edit', $propietario))->assertForbidden();
        $this->actingAs($firstUser)
            ->put(route('propietarios.update', $propietario), $this->ownerData())
            ->assertForbidden();
        $this->actingAs($secondUser)
            ->patch(route('propietarios.estado', $propietario), ['estado' => 'inactivo'])
            ->assertForbidden();
    }

    public function test_users_cannot_access_owners_or_ownership_from_unassigned_buildings(): void
    {
        [$ownerUser, $firstBuilding, $firstDepartment] = $this->buildingFixture();
        [$otherUser, $secondBuilding, $secondDepartment] = $this->buildingFixture();
        $propietario = $this->createOwner($firstBuilding, '1718000001');

        $this->actingAs($otherUser)
            ->get(route('propietarios.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('propietarios.data', 0));
        $this->actingAs($otherUser)->get(route('propietarios.show', $propietario))->assertForbidden();
        $this->actingAs($otherUser)->get(route('propietarios.edit', $propietario))->assertForbidden();
        $this->actingAs($otherUser)
            ->put(route('propietarios.update', $propietario), $this->ownerData())
            ->assertForbidden();
        $this->actingAs($otherUser)
            ->patch(route('propietarios.estado', $propietario), ['estado' => 'inactivo'])
            ->assertForbidden();
        $this->actingAs($otherUser)
            ->post(route('departamentos.propietarios.assign', [$secondBuilding, $secondDepartment->id]), [
                'propietario_id' => $propietario->id,
                'porcentaje' => '100.000000',
                'fecha_inicio' => '2026-01-01',
            ])
            ->assertSessionHasErrors('propietarioId');
        $this->actingAs($ownerUser)
            ->post(route('departamentos.propietarios.assign', [$firstBuilding, $secondDepartment->id]), [
                'propietario_id' => $propietario->id,
                'porcentaje' => '100.000000',
                'fecha_inicio' => '2026-01-01',
            ])
            ->assertSessionHasErrors('departamentoId');

        $this->assertDatabaseCount('departamento_propietarios', 0);
    }

    public function test_database_constraints_protect_cross_building_and_duplicate_active_relations(): void
    {
        [$user, $firstBuilding, $firstDepartment] = $this->buildingFixture();
        [, $secondBuilding, $secondDepartment] = $this->buildingFixture();
        $propietario = $this->createOwner($firstBuilding, '1719000001');

        try {
            DB::transaction(static fn () => DepartamentoPropietarioEloquentModel::query()->forceCreate([
                'edificio_id' => $firstBuilding->id,
                'departamento_id' => $secondDepartment->id,
                'propietario_id' => $propietario->id,
                'nombre_propietario' => 'Juan Pérez',
                'tipo_identificacion_snapshot' => 'cedula',
                'identificacion_snapshot' => $propietario->identificacion,
                'porcentaje' => '50.000000',
                'fecha_inicio' => '2026-01-01',
                'fecha_fin' => null,
                'estado' => 'activa',
            ]));
            $this->fail('La base permitió relacionar una titularidad con un departamento de otro edificio.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
        }

        $first = DepartamentoPropietarioEloquentModel::query()->forceCreate([
            'edificio_id' => $firstBuilding->id,
            'departamento_id' => $firstDepartment->id,
            'propietario_id' => $propietario->id,
            'nombre_propietario' => 'Juan Pérez',
            'tipo_identificacion_snapshot' => 'cedula',
            'identificacion_snapshot' => $propietario->identificacion,
            'porcentaje' => '50.000000',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => null,
            'estado' => 'activa',
        ]);

        try {
            DB::transaction(static fn () => DepartamentoPropietarioEloquentModel::query()->forceCreate([
                'edificio_id' => $firstBuilding->id,
                'departamento_id' => $firstDepartment->id,
                'propietario_id' => $propietario->id,
                'nombre_propietario' => 'Juan Pérez',
                'tipo_identificacion_snapshot' => 'cedula',
                'identificacion_snapshot' => $propietario->identificacion,
                'porcentaje' => '10.000000',
                'fecha_inicio' => '2026-02-01',
                'fecha_fin' => null,
                'estado' => 'activa',
            ]));
            $this->fail('La base permitió una relación activa duplicada.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('unique', strtolower($exception->getMessage()));
        }

        $this->assertDatabaseCount('departamento_propietarios', 1);
        $this->assertSame($first->id, DepartamentoPropietarioEloquentModel::query()->sole()->id);
        $this->assertNotSame($firstBuilding->id, $secondBuilding->id);
        $this->assertNotNull($user->id);
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel, DepartamentoEloquentModel} */
    private function buildingFixture(): array
    {
        $user = UserEloquentModel::factory()->create();
        /** @var CreateEdificioAction $action */
        $action = $this->app->make(CreateEdificioAction::class);
        $created = $action->execute([
            'nombre' => 'Edificio '.Str::upper(Str::random(6)),
            'ruc' => Str::upper(Str::random(13)),
            'direccion' => 'Av. Principal 123',
            'ciudad' => 'Quito',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $user->id);
        $edificio = EdificioEloquentModel::query()->findOrFail($created->id());
        $torre = $edificio->torres()->sole();
        $piso = PisoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'torre_id' => $torre->id,
            'numero' => '1',
            'nombre' => 'Primer piso',
            'orden' => 1,
            'estado' => 'activo',
        ]);
        $departamento = DepartamentoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'piso_id' => $piso->id,
            'codigo' => 'A-101',
            'nombre' => 'Departamento A-101',
            'alicuota' => '5.000000',
            'estado' => 'activo',
        ]);

        return [$user, $edificio, $departamento];
    }

    /** @param array<string, mixed> $overrides */
    private function createOwner(
        EdificioEloquentModel $edificio,
        string $identificacion,
        array $overrides = [],
    ): PropietarioEloquentModel {
        $propietario = PropietarioEloquentModel::query()->forceCreate(array_merge([
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
        ], $overrides));
        $propietario->edificios()->attach($edificio->id);

        return $propietario;
    }

    private function assign(
        UserEloquentModel $user,
        EdificioEloquentModel $edificio,
        DepartamentoEloquentModel $departamento,
        PropietarioEloquentModel $propietario,
        string $porcentaje,
    ): void {
        $this->actingAs($user)
            ->post(route('departamentos.propietarios.assign', [$edificio, $departamento->id]), [
                'propietario_id' => $propietario->id,
                'porcentaje' => $porcentaje,
                'fecha_inicio' => '2026-01-01',
            ])
            ->assertSessionHasNoErrors();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function ownerData(array $overrides = []): array
    {
        return array_merge([
            'tipo_persona' => 'persona_natural',
            'nombres' => 'Ana María',
            'apellidos' => 'Torres López',
            'razon_social' => null,
            'tipo_identificacion' => 'cedula',
            'identificacion' => '1712345678',
            'telefono' => '022345678',
            'celular' => '0991234567',
            'correo' => 'ana@example.test',
            'direccion' => 'Av. República 100',
            'observaciones' => null,
        ], $overrides);
    }
}
