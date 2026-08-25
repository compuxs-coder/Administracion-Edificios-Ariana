<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Infrastructure\Models\BodegaEloquentModel;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\DepartamentoParqueaderoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\ParqueaderoEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Edificio\Infrastructure\Models\TorreEloquentModel;
use Tests\TestCase;

final class EstructuraFisicaWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_building_receives_a_single_default_tower(): void
    {
        $user = UserEloquentModel::factory()->create();

        $this->actingAs($user)
            ->post(route('edificios.store'), $this->buildingData())
            ->assertRedirect(route('edificios.index'));

        $edificio = EdificioEloquentModel::query()->sole();
        $torre = TorreEloquentModel::query()->sole();

        $this->assertSame($edificio->id, $torre->edificio_id);
        $this->assertSame('PRINCIPAL', $torre->codigo);
        $this->assertTrue($torre->es_predeterminada);
        $this->assertSame('activo', $torre->estado->value);
    }

    public function test_assigned_user_manages_hierarchy_and_independent_annexes(): void
    {
        [$user, $edificio, $principal] = $this->buildingFixture();

        $this->actingAs($user)
            ->post(route('edificios.torres.store', $edificio), [
                'codigo' => 'norte',
                'nombre' => 'Torre Norte',
                'descripcion' => 'Bloque de acceso norte',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $norte = TorreEloquentModel::query()->where('codigo', 'NORTE')->sole();

        $this->actingAs($user)
            ->post(route('edificios.pisos.store', $edificio), [
                'torre_id' => $principal->id,
                'numero' => 'pb',
                'nombre' => 'Planta baja',
                'orden' => 0,
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('edificios.parqueaderos.store', $edificio), [
                'torre_id' => $norte->id,
                'codigo' => 'p-001',
                'ubicacion' => 'Subsuelo 1',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('edificios.bodegas.store', $edificio), [
                'torre_id' => null,
                'codigo' => 'b-001',
                'ubicacion' => 'Área común',
            ])
            ->assertRedirect();

        $piso = PisoEloquentModel::query()->sole();
        $parqueadero = ParqueaderoEloquentModel::query()->sole();
        $bodega = BodegaEloquentModel::query()->sole();

        $this->actingAs($user)
            ->put(route('edificios.torres.update', [$edificio, $norte->id]), [
                'codigo' => 'NORTE',
                'nombre' => 'Bloque Norte',
                'descripcion' => null,
            ])
            ->assertRedirect();
        $this->actingAs($user)
            ->put(route('edificios.pisos.update', [$edificio, $piso->id]), [
                'torre_id' => $principal->id,
                'numero' => 'PB',
                'nombre' => 'Nivel de acceso',
                'orden' => 0,
            ])
            ->assertRedirect();
        $this->actingAs($user)
            ->put(route('edificios.parqueaderos.update', [$edificio, $parqueadero->id]), [
                'torre_id' => $norte->id,
                'codigo' => 'P-001',
                'ubicacion' => 'Subsuelo renovado',
            ])
            ->assertRedirect();
        $this->actingAs($user)
            ->put(route('edificios.bodegas.update', [$edificio, $bodega->id]), [
                'torre_id' => null,
                'codigo' => 'B-001',
                'ubicacion' => 'Área común renovada',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->patch(route('edificios.estructura.estado', [$edificio, 'torre', $norte->id]), ['estado' => 'inactivo'])
            ->assertSessionHasErrors('estado');

        $this->assertDatabaseHas('pisos', [
            'edificio_id' => $edificio->id,
            'torre_id' => $principal->id,
            'numero' => 'PB',
            'nombre' => 'Nivel de acceso',
        ]);
        $this->assertDatabaseHas('parqueaderos', ['codigo' => 'P-001', 'ubicacion' => 'Subsuelo renovado']);
        $this->assertDatabaseHas('bodegas', ['codigo' => 'B-001', 'ubicacion' => 'Área común renovada']);

        $this->actingAs($user)
            ->get(route('edificios.estructura', $edificio))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Edificio/estructura')
                ->where('edificio.id', $edificio->id)
                ->has('estructura.torres', 2)
                ->has('estructura.parqueaderos', 1)
                ->has('estructura.bodegas', 1));
    }

    public function test_codes_are_unique_in_each_building_and_cross_building_parents_are_rejected(): void
    {
        $user = UserEloquentModel::factory()->create();
        $first = $this->createBuilding($user, 'Edificio Uno', 'RUC-001');
        $second = $this->createBuilding($user, 'Edificio Dos', 'RUC-002');
        $firstTower = $first->torres()->sole();
        $secondTower = $second->torres()->sole();

        foreach ([$first, $second] as $building) {
            $this->actingAs($user)
                ->post(route('edificios.parqueaderos.store', $building), [
                    'codigo' => 'P-001',
                    'ubicacion' => null,
                    'torre_id' => null,
                ])
                ->assertRedirect();
        }

        $this->actingAs($user)
            ->post(route('edificios.parqueaderos.store', $first), [
                'codigo' => 'P-001',
                'ubicacion' => null,
                'torre_id' => null,
            ])
            ->assertSessionHasErrors('codigo');

        $this->actingAs($user)
            ->post(route('edificios.pisos.store', $first), [
                'torre_id' => $secondTower->id,
                'numero' => '1',
                'nombre' => null,
                'orden' => 1,
            ])
            ->assertSessionHasErrors('torreId');

        $this->assertDatabaseMissing('pisos', [
            'edificio_id' => $first->id,
            'torre_id' => $secondTower->id,
        ]);

        $this->actingAs($user)
            ->post(route('edificios.pisos.store', $first), [
                'torre_id' => $firstTower->id,
                'numero' => '1',
                'nombre' => null,
                'orden' => 1,
            ])
            ->assertRedirect();
    }

    public function test_department_is_created_with_multiple_annexes_and_appears_in_filtered_list(): void
    {
        [$user, $edificio, $torre] = $this->buildingFixture();
        $piso = $this->createFloor($edificio, $torre);
        $parqueadero1 = $this->createParking($edificio, 'P-001');
        $parqueadero2 = $this->createParking($edificio, 'P-002');
        $bodega = $this->createStorage($edificio, 'B-001');

        $this->actingAs($user)
            ->post(route('departamentos.store', $edificio), $this->departmentData($piso, [
                'parqueaderos' => [$parqueadero1->id, $parqueadero2->id],
                'bodegas' => [$bodega->id],
            ]))
            ->assertRedirect(route('departamentos.index'))
            ->assertSessionHas('success');

        $departamento = DepartamentoEloquentModel::query()->sole();
        $this->assertSame('3.250000', $departamento->alicuota);
        $this->assertDatabaseCount('departamento_parqueaderos', 2);
        $this->assertDatabaseCount('departamento_bodegas', 1);

        $this->actingAs($user)
            ->get(route('departamentos.index', [
                'edificio_id' => $edificio->id,
                'torre_id' => $torre->id,
                'estado' => 'activo',
                'buscar' => '301',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Departamento/index')
                ->has('departamentos.data', 1)
                ->where('departamentos.data.0.id', $departamento->id)
                ->has('departamentos.data.0.parqueaderos', 2)
                ->has('departamentos.data.0.bodegas', 1));
    }

    public function test_department_validation_rejects_invalid_values_and_duplicate_code_in_same_building(): void
    {
        [$user, $edificio, $torre] = $this->buildingFixture();
        $piso = $this->createFloor($edificio, $torre);

        $this->actingAs($user)
            ->post(route('departamentos.store', $edificio), [
                'piso_id' => '00000000-0000-0000-0000-000000000000',
                'codigo' => '',
                'nombre' => '',
                'alicuota' => '100.000001',
                'estado' => 'inactivo',
                'parqueaderos' => ['valor-invalido'],
                'bodegas' => [],
            ])
            ->assertSessionHasErrors(['codigo', 'nombre', 'alicuota', 'parqueaderos.0']);

        $this->assertDatabaseCount('departamentos', 0);

        $this->actingAs($user)
            ->post(route('departamentos.store', $edificio), $this->departmentData($piso, ['estado' => 'inactivo']))
            ->assertRedirect(route('departamentos.index'));

        $this->assertDatabaseHas('departamentos', ['codigo' => 'DEP-301', 'estado' => 'activo']);

        $this->actingAs($user)
            ->post(route('departamentos.store', $edificio), $this->departmentData($piso, [
                'codigo' => 'dep-301',
                'nombre' => 'Código repetido',
            ]))
            ->assertSessionHasErrors('codigo');

        $this->assertDatabaseCount('departamentos', 1);
    }

    public function test_annex_assignments_are_exclusive_and_changes_keep_history(): void
    {
        [$user, $edificio, $torre] = $this->buildingFixture();
        $piso = $this->createFloor($edificio, $torre);
        $parqueadero = $this->createParking($edificio, 'P-010');

        $this->actingAs($user)
            ->post(route('departamentos.store', $edificio), $this->departmentData($piso, [
                'parqueaderos' => [$parqueadero->id],
            ]))
            ->assertRedirect(route('departamentos.index'));
        $first = DepartamentoEloquentModel::query()->sole();

        $this->actingAs($user)
            ->post(route('departamentos.store', $edificio), $this->departmentData($piso, [
                'codigo' => 'DEP-302',
                'nombre' => 'Departamento 302',
                'parqueaderos' => [$parqueadero->id],
            ]))
            ->assertSessionHasErrors('parqueaderos');

        $this->assertDatabaseCount('departamentos', 1);

        $this->actingAs($user)
            ->put(route('departamentos.update', [$edificio, $first->id]), $this->departmentData($piso, [
                'parqueaderos' => [],
            ]))
            ->assertRedirect(route('departamentos.index'));

        $closedAssignment = DepartamentoParqueaderoEloquentModel::query()->sole();
        $this->assertNotNull($closedAssignment->fecha_fin);

        $this->actingAs($user)
            ->post(route('departamentos.store', $edificio), $this->departmentData($piso, [
                'codigo' => 'DEP-302',
                'nombre' => 'Departamento 302',
                'parqueaderos' => [$parqueadero->id],
            ]))
            ->assertRedirect(route('departamentos.index'));

        $this->assertDatabaseCount('departamento_parqueaderos', 2);
        $this->assertSame(1, DepartamentoParqueaderoEloquentModel::query()->whereNull('fecha_fin')->count());
    }

    public function test_inactivation_rules_preserve_consistent_structure_and_release_annexes(): void
    {
        [$user, $edificio, $torre] = $this->buildingFixture();
        $piso = $this->createFloor($edificio, $torre);
        $parqueadero = $this->createParking($edificio, 'P-020');

        $this->actingAs($user)
            ->post(route('departamentos.store', $edificio), $this->departmentData($piso, [
                'parqueaderos' => [$parqueadero->id],
            ]));
        $departamento = DepartamentoEloquentModel::query()->sole();

        $this->actingAs($user)
            ->patch(route('edificios.estructura.estado', [$edificio, 'parqueadero', $parqueadero->id]), ['estado' => 'inactivo'])
            ->assertSessionHasErrors('estado');

        $this->actingAs($user)
            ->patch(route('edificios.estructura.estado', [$edificio, 'piso', $piso->id]), ['estado' => 'inactivo'])
            ->assertSessionHasErrors('estado');

        $this->actingAs($user)
            ->patch(route('departamentos.estado', [$edificio, $departamento->id]), ['estado' => 'inactivo'])
            ->assertRedirect();

        $this->assertDatabaseHas('departamentos', ['id' => $departamento->id, 'estado' => 'inactivo']);
        $this->assertDatabaseMissing('departamento_parqueaderos', [
            'departamento_id' => $departamento->id,
            'fecha_fin' => null,
        ]);

        $this->actingAs($user)
            ->put(route('departamentos.update', [$edificio, $departamento->id]), $this->departmentData($piso, [
                'parqueaderos' => [$parqueadero->id],
            ]))
            ->assertSessionHasErrors('parqueaderos');

        $this->assertDatabaseMissing('departamento_parqueaderos', [
            'departamento_id' => $departamento->id,
            'fecha_fin' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('edificios.estructura.estado', [$edificio, 'parqueadero', $parqueadero->id]), ['estado' => 'inactivo'])
            ->assertRedirect();
        $this->actingAs($user)
            ->patch(route('edificios.estructura.estado', [$edificio, 'piso', $piso->id]), ['estado' => 'inactivo'])
            ->assertRedirect();

        $this->actingAs($user)
            ->patch(route('edificios.estructura.estado', [$edificio, 'torre', $torre->id]), ['estado' => 'inactivo'])
            ->assertSessionHasErrors('estado');
    }

    public function test_unassigned_user_cannot_see_or_modify_physical_structure(): void
    {
        [$owner, $edificio, $torre] = $this->buildingFixture();
        $piso = $this->createFloor($edificio, $torre);
        $departamento = $this->createDepartment($edificio, $piso, 'D-SEGURA');
        $outsider = UserEloquentModel::factory()->create();
        $allowedBuilding = $this->createBuilding($outsider, 'Edificio Permitido', 'RUC-PERMITIDO');

        $this->actingAs($outsider)
            ->get(route('edificios.estructura', $edificio))
            ->assertForbidden();
        $this->actingAs($outsider)
            ->post(route('edificios.torres.store', $edificio), [
                'codigo' => 'ILEGAL',
                'nombre' => 'Sin acceso',
            ])
            ->assertForbidden();
        $this->actingAs($outsider)
            ->put(route('edificios.pisos.update', [$edificio, $piso->id]), [
                'torre_id' => $torre->id,
                'numero' => '4',
                'nombre' => 'Intento',
                'orden' => 4,
            ])
            ->assertForbidden();
        $this->actingAs($outsider)
            ->patch(route('edificios.estructura.estado', [$edificio, 'piso', $piso->id]), ['estado' => 'inactivo'])
            ->assertForbidden();
        $this->actingAs($outsider)
            ->get(route('departamentos.edit', [$edificio, $departamento->id]))
            ->assertForbidden();
        $this->actingAs($outsider)
            ->put(route('departamentos.update', [$edificio, $departamento->id]), $this->departmentData($piso))
            ->assertForbidden();
        $this->actingAs($outsider)
            ->patch(route('departamentos.estado', [$edificio, $departamento->id]), ['estado' => 'inactivo'])
            ->assertForbidden();
        $this->actingAs($outsider)
            ->get(route('departamentos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('departamentos.data', 0));
        $this->actingAs($outsider)
            ->post(route('departamentos.store', $allowedBuilding), $this->departmentData($piso))
            ->assertSessionHasErrors('pisoId');

        $this->assertDatabaseCount('torres', 2);
        $this->assertDatabaseHas('departamentos', ['id' => $departamento->id, 'estado' => 'activo']);
        $this->assertNotSame($owner->id, $outsider->id);
    }

    public function test_database_constraints_reject_cross_building_hierarchy_and_duplicate_open_assignment(): void
    {
        $user = UserEloquentModel::factory()->create();
        $first = $this->createBuilding($user, 'Edificio Uno', 'RUC-101');
        $second = $this->createBuilding($user, 'Edificio Dos', 'RUC-102');
        $firstTower = $first->torres()->sole();
        $secondTower = $second->torres()->sole();

        try {
            DB::transaction(static fn () => PisoEloquentModel::query()->forceCreate([
                    'edificio_id' => $first->id,
                    'torre_id' => $secondTower->id,
                    'numero' => 'X',
                    'orden' => 99,
                    'estado' => 'activo',
                ]));
            $this->fail('La base permitió relacionar un piso con una torre de otro edificio.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
        }

        try {
            DB::transaction(static fn () => TorreEloquentModel::query()->forceCreate([
                    'edificio_id' => $first->id,
                    'codigo' => 'OTRA-PRINCIPAL',
                    'nombre' => 'Otra principal',
                    'es_predeterminada' => true,
                    'estado' => 'activo',
                ]));
            $this->fail('La base permitió más de una torre predeterminada.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('unique', strtolower($exception->getMessage()));
        }

        $piso = $this->createFloor($first, $firstTower);
        $firstDepartment = $this->createDepartment($first, $piso, 'D-1');
        $secondDepartment = $this->createDepartment($first, $piso, 'D-2');
        $parqueadero = $this->createParking($first, 'P-DB');
        $this->createParkingAssignment($first, $firstDepartment, $parqueadero);

        try {
            DB::transaction(fn () => $this->createParkingAssignment($first, $secondDepartment, $parqueadero));
            $this->fail('La base permitió dos asignaciones vigentes para un parqueadero.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('unique', strtolower($exception->getMessage()));
        }

        $this->assertFalse(Route::has('departamentos.destroy'));
        $this->assertFalse(Route::has('edificios.torres.destroy'));
        $this->assertDatabaseCount('pisos', 1);
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel, TorreEloquentModel} */
    private function buildingFixture(): array
    {
        $user = UserEloquentModel::factory()->create();
        $edificio = $this->createBuilding($user, 'Edificio Central', 'RUC-CENTRAL');

        return [$user, $edificio, $edificio->torres()->sole()];
    }

    private function createBuilding(
        UserEloquentModel $user,
        string $nombre,
        string $ruc,
    ): EdificioEloquentModel {
        /** @var CreateEdificioAction $action */
        $action = $this->app->make(CreateEdificioAction::class);
        $created = $action->execute($this->buildingData([
            'nombre' => $nombre,
            'ruc' => $ruc,
        ]), $user->id);

        return EdificioEloquentModel::query()->findOrFail($created->id());
    }

    private function createFloor(
        EdificioEloquentModel $edificio,
        TorreEloquentModel $torre,
    ): PisoEloquentModel {
        return PisoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'torre_id' => $torre->id,
            'numero' => '3',
            'nombre' => 'Tercer piso',
            'orden' => 3,
            'estado' => 'activo',
        ]);
    }

    private function createParking(EdificioEloquentModel $edificio, string $codigo): ParqueaderoEloquentModel
    {
        return ParqueaderoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'torre_id' => null,
            'codigo' => $codigo,
            'estado' => 'activo',
        ]);
    }

    private function createStorage(EdificioEloquentModel $edificio, string $codigo): BodegaEloquentModel
    {
        return BodegaEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'torre_id' => null,
            'codigo' => $codigo,
            'estado' => 'activo',
        ]);
    }

    private function createDepartment(
        EdificioEloquentModel $edificio,
        PisoEloquentModel $piso,
        string $codigo,
    ): DepartamentoEloquentModel {
        return DepartamentoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'piso_id' => $piso->id,
            'codigo' => $codigo,
            'nombre' => $codigo,
            'alicuota' => '1.000000',
            'estado' => 'activo',
        ]);
    }

    private function createParkingAssignment(
        EdificioEloquentModel $edificio,
        DepartamentoEloquentModel $departamento,
        ParqueaderoEloquentModel $parqueadero,
    ): DepartamentoParqueaderoEloquentModel {
        return DepartamentoParqueaderoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamento->id,
            'parqueadero_id' => $parqueadero->id,
            'fecha_inicio' => now(),
            'fecha_fin' => null,
        ]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function buildingData(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Edificio Central',
            'ruc' => 'RUC-CENTRAL',
            'direccion' => 'Av. Principal 123',
            'ciudad' => 'Quito',
            'telefono' => '+593 2 555 0100',
            'correo' => 'administracion@central.test',
            'responsable' => 'Ana Administradora',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function departmentData(PisoEloquentModel $piso, array $overrides = []): array
    {
        return array_merge([
            'piso_id' => $piso->id,
            'codigo' => 'DEP-301',
            'nombre' => 'Departamento 301',
            'alicuota' => '3.250000',
            'estado' => 'activo',
            'observaciones' => null,
            'parqueaderos' => [],
            'bodegas' => [],
        ], $overrides);
    }
}
