<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;
use Src\Finanzas\Infrastructure\Models\TarifaConceptoEloquentModel;
use Tests\TestCase;

final class FinanzasWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_routes_require_authentication(): void
    {
        [$user, $edificio] = $this->buildingFixture();
        $concepto = $this->createConcept($edificio, 'ALICUOTA');

        $this->get(route('conceptos.index'))->assertRedirect(route('login'));
        $this->get(route('conceptos.create'))->assertRedirect(route('login'));
        $this->post(route('conceptos.store', $edificio), $this->conceptData())->assertRedirect(route('login'));
        $this->get(route('conceptos.show', [$edificio, $concepto]))->assertRedirect(route('login'));
        $this->get(route('conceptos.edit', [$edificio, $concepto]))->assertRedirect(route('login'));
        $this->put(route('conceptos.update', [$edificio, $concepto]), $this->conceptData())->assertRedirect(route('login'));
        $this->patch(route('conceptos.estado', [$edificio, $concepto]), ['estado' => 'inactivo'])->assertRedirect(route('login'));
        $this->post(route('conceptos.tarifas.store', [$edificio, $concepto]), $this->rateData())->assertRedirect(route('login'));

        $this->assertDatabaseCount('tarifas_concepto', 0);
        $this->assertNotNull($user->id);
    }

    public function test_user_creates_lists_edits_and_changes_concept_status(): void
    {
        [$user, $edificio] = $this->buildingFixture();

        $response = $this->actingAs($user)
            ->post(route('conceptos.store', $edificio), $this->conceptData())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $concepto = ConceptoCobroEloquentModel::query()->where('codigo', 'ALICUOTA')->sole();
        $response->assertRedirect(route('conceptos.show', [$edificio->id, $concepto->id]));
        $this->assertDatabaseHas('conceptos_cobro', [
            'id' => $concepto->id,
            'edificio_id' => $edificio->id,
            'tipo' => 'ordinario',
            'forma_calculo' => 'por_alicuota',
            'estado' => 'activo',
        ]);

        $this->actingAs($user)
            ->get(route('conceptos.index', ['buscar' => 'ALIC']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Concepto/index')
                ->has('conceptos.data', 1)
                ->where('conceptos.data.0.id', $concepto->id)
                ->where('conceptos.data.0.tarifaVigente', null));

        $this->actingAs($user)
            ->put(route('conceptos.update', [$edificio, $concepto]), $this->conceptData([
                'codigo' => 'alicuota-general',
                'nombre' => 'Presupuesto ordinario',
                'estado' => 'inactivo',
            ]))
            ->assertRedirect(route('conceptos.show', [$edificio->id, $concepto->id]));
        $this->assertDatabaseHas('conceptos_cobro', [
            'id' => $concepto->id,
            'codigo' => 'ALICUOTA-GENERAL',
            'estado' => 'inactivo',
        ]);

        $this->actingAs($user)
            ->patch(route('conceptos.estado', [$edificio, $concepto]), ['estado' => 'activo'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('conceptos_cobro', ['id' => $concepto->id, 'estado' => 'activo']);
    }

    public function test_concept_validation_rejects_duplicate_code_and_invalid_financial_type_setup(): void
    {
        [$user, $edificio] = $this->buildingFixture();
        $this->createConcept($edificio, 'ALICUOTA');

        $this->actingAs($user)
            ->post(route('conceptos.store', $edificio), $this->conceptData())
            ->assertSessionHasErrors('codigo');
        $this->actingAs($user)
            ->post(route('conceptos.store', $edificio), $this->conceptData([
                'codigo' => 'GAS',
                'tipo' => 'consumo',
                'forma_calculo' => 'valor_fijo',
            ]))
            ->assertSessionHasErrors('formaCalculo');
        $this->actingAs($user)
            ->post(route('conceptos.store', $edificio), $this->conceptData([
                'codigo' => 'INTERES-MORA',
                'tipo' => 'interes',
                'forma_calculo' => 'valor_fijo',
            ]))
            ->assertSessionHasErrors('formaCalculo');

        $this->assertDatabaseCount('conceptos_cobro', 1);
    }

    public function test_tariff_change_closes_previous_rate_preserves_history_and_rejects_overlap(): void
    {
        [$user, $edificio] = $this->buildingFixture();
        $concepto = $this->createConcept($edificio, 'ALICUOTA');
        $today = CarbonImmutable::today();

        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $concepto]), $this->rateData([
                'valor' => '8000.0000',
                'fecha_inicio' => $today->subDay()->format('Y-m-d'),
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $anterior = TarifaConceptoEloquentModel::query()->sole();

        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $concepto]), $this->rateData([
                'valor' => '9000.0000',
                'fecha_inicio' => $today->format('Y-m-d'),
                'observacion' => 'Presupuesto aprobado',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($today->format('Y-m-d'), $anterior->refresh()->fecha_fin->format('Y-m-d'));
        $this->assertSame('8000.0000', $anterior->valor);
        $this->assertDatabaseHas('tarifas_concepto', [
            'concepto_cobro_id' => $concepto->id,
            'valor' => '9000',
            'fecha_fin' => null,
        ]);
        $this->assertDatabaseCount('tarifas_concepto', 2);

        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $concepto]), $this->rateData([
                'valor' => '8500.0000',
                'fecha_inicio' => $today->subDay()->format('Y-m-d'),
            ]))
            ->assertSessionHasErrors('fechaInicio');
        $this->assertDatabaseCount('tarifas_concepto', 2);

        $this->actingAs($user)
            ->get(route('conceptos.show', [$edificio, $concepto]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Concepto/show')
                ->where('concepto.tarifaVigente.valor', '9000.0000')
                ->where('concepto.tarifas.0.estado', 'vigente')
                ->where('concepto.tarifas.1.estado', 'finalizada'));
    }

    public function test_tariffs_support_building_and_specific_department_scope_without_cross_building_links(): void
    {
        [$user, $edificio, $departamentos] = $this->buildingFixture(2);
        [, $otroEdificio, $otrosDepartamentos] = $this->buildingFixture(1);
        $all = $this->createConcept($edificio, 'MULTA-RUIDO', ['tipo' => 'multa', 'forma_calculo' => 'valor_fijo', 'periodicidad' => 'manual']);
        $specific = $this->createConcept($edificio, 'ASCENSOR', ['tipo' => 'extraordinario', 'forma_calculo' => 'valor_fijo']);

        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $all]), $this->rateData(['valor' => '25.0000']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tarifa_departamentos', 0);

        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $specific]), $this->rateData([
                'valor' => '100.0000',
                'monto_total' => '2400.0000',
                'numero_cuotas' => 24,
                'alcance' => 'departamentos_especificos',
                'departamentos' => [$departamentos[0]->id, $departamentos[1]->id],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tarifa_departamentos', 2);

        $otroConcepto = $this->createConcept($edificio, 'FACHADA', ['tipo' => 'extraordinario', 'forma_calculo' => 'valor_fijo']);
        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $otroConcepto]), $this->rateData([
                'valor' => '50.0000',
                'alcance' => 'departamentos_especificos',
                'departamentos' => [$otrosDepartamentos[0]->id],
            ]))
            ->assertSessionHasErrors('departamentos');
        $this->assertDatabaseCount('tarifa_departamentos', 2);
        $this->assertNotSame($edificio->id, $otroEdificio->id);
    }

    public function test_consumption_interest_fine_and_extraordinary_configuration_are_validated(): void
    {
        [$user, $edificio, $departamentos] = $this->buildingFixture();
        $gas = $this->createConcept($edificio, 'GAS', ['tipo' => 'consumo', 'forma_calculo' => 'por_consumo']);
        $interes = $this->createConcept($edificio, 'INTERES-MORA', ['tipo' => 'interes', 'forma_calculo' => 'porcentaje']);
        $multa = $this->createConcept($edificio, 'MULTA-RUIDO', ['tipo' => 'multa', 'periodicidad' => 'manual', 'forma_calculo' => 'valor_fijo']);

        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $gas]), $this->rateData(['valor' => '0.2500']))
            ->assertSessionHasErrors('unidad');
        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $gas]), $this->rateData(['valor' => '0.2500', 'unidad' => 'm3']))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $interes]), $this->rateData(['porcentaje' => '1.500000']))
            ->assertSessionHasErrors('baseCalculo');
        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $interes]), $this->rateData(['porcentaje' => '1.500000', 'base_calculo' => 'saldo_vencido']))
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $multa]), $this->rateData(['valor' => '35.0000']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tarifas_concepto', ['concepto_cobro_id' => $gas->id, 'unidad' => 'm3', 'valor' => '0.25']);
        $this->assertDatabaseHas('tarifas_concepto', ['concepto_cobro_id' => $interes->id, 'porcentaje' => '1.5', 'base_calculo' => 'saldo_vencido']);
        $this->assertDatabaseHas('tarifas_concepto', ['concepto_cobro_id' => $multa->id, 'valor' => '35']);
        $this->assertDatabaseCount('tarifa_departamentos', 0);
        $this->assertNotNull($departamentos[0]->alicuota);
    }

    public function test_concept_configuration_and_inactive_concepts_cannot_change_tariff_semantics(): void
    {
        [$user, $edificio] = $this->buildingFixture();
        $concepto = $this->createConcept($edificio, 'ALICUOTA');
        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $concepto]), $this->rateData())
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->put(route('conceptos.update', [$edificio, $concepto]), $this->conceptData([
                'tipo' => 'consumo',
                'forma_calculo' => 'por_consumo',
            ]))
            ->assertSessionHasErrors(['tipo', 'formaCalculo']);
        $this->assertDatabaseHas('conceptos_cobro', [
            'id' => $concepto->id,
            'tipo' => 'ordinario',
            'forma_calculo' => 'por_alicuota',
        ]);

        $inactivo = $this->createConcept($edificio, 'MULTA-INACTIVA', [
            'tipo' => 'multa',
            'periodicidad' => 'manual',
            'forma_calculo' => 'valor_fijo',
            'estado' => 'inactivo',
        ]);
        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $inactivo]), $this->rateData(['valor' => '20.0000']))
            ->assertSessionHasErrors('concepto');
        $this->assertDatabaseCount('tarifas_concepto', 1);
    }

    public function test_programmed_tariff_and_extraordinary_pair_validation_are_exposed_correctly(): void
    {
        [$user, $edificio] = $this->buildingFixture();
        $programado = $this->createConcept($edificio, 'FUTURO');
        $extraordinario = $this->createConcept($edificio, 'OBRA', [
            'tipo' => 'extraordinario',
            'forma_calculo' => 'valor_fijo',
        ]);

        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $programado]), $this->rateData([
                'fecha_inicio' => CarbonImmutable::today()->addDay()->format('Y-m-d'),
            ]))
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->get(route('conceptos.show', [$edificio, $programado]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('concepto.tarifaVigente', null)
                ->where('concepto.tarifas.0.estado', 'programada'));

        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $extraordinario]), $this->rateData([
                'monto_total' => '1000.0000',
            ]))
            ->assertSessionHasErrors(['montoTotal', 'numeroCuotas']);
    }

    public function test_users_cannot_read_or_mutate_concepts_from_other_buildings(): void
    {
        [$owner, $edificio] = $this->buildingFixture();
        [$other, $otroEdificio] = $this->buildingFixture();
        $concepto = $this->createConcept($edificio, 'ALICUOTA');

        $this->actingAs($other)->get(route('conceptos.index'))->assertInertia(fn (Assert $page) => $page->has('conceptos.data', 0));
        $this->actingAs($other)->get(route('conceptos.show', [$edificio, $concepto]))->assertForbidden();
        $this->actingAs($other)->put(route('conceptos.update', [$edificio, $concepto]), $this->conceptData())->assertForbidden();
        $this->actingAs($other)->post(route('conceptos.store', $edificio), $this->conceptData(['codigo' => 'OTRO']))->assertForbidden();
        $this->actingAs($other)->patch(route('conceptos.estado', [$edificio, $concepto]), ['estado' => 'inactivo'])->assertForbidden();
        $this->actingAs($other)->post(route('conceptos.tarifas.store', [$edificio, $concepto]), $this->rateData())->assertForbidden();
        $extranjero = $this->createConcept($otroEdificio, 'OTRO');
        $this->actingAs($owner)->get(route('conceptos.show', [$edificio, $extranjero]))->assertNotFound();
        $this->actingAs($owner)->post(route('conceptos.tarifas.store', [$edificio, $extranjero]), $this->rateData())->assertNotFound();
    }

    public function test_database_constraints_reject_cross_building_tariffs_and_duplicate_open_rates(): void
    {
        [, $firstBuilding] = $this->buildingFixture();
        [, $secondBuilding, $departamentos] = $this->buildingFixture();
        $concepto = $this->createConcept($firstBuilding, 'ALICUOTA');

        try {
            DB::transaction(static fn () => TarifaConceptoEloquentModel::query()->forceCreate([
                'edificio_id' => $secondBuilding->id,
                'concepto_cobro_id' => $concepto->id,
                'valor' => '80.0000',
                'fecha_inicio' => '2026-01-01',
                'fecha_fin' => null,
                'alcance' => 'todo_el_edificio',
            ]));
            $this->fail('La base permitió una tarifa con concepto de otro edificio.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
        }

        $tarifa = TarifaConceptoEloquentModel::query()->forceCreate([
            'edificio_id' => $firstBuilding->id,
            'concepto_cobro_id' => $concepto->id,
            'valor' => '80.0000',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => null,
            'alcance' => 'todo_el_edificio',
        ]);
        try {
            DB::transaction(static fn () => TarifaConceptoEloquentModel::query()->forceCreate([
                'edificio_id' => $firstBuilding->id,
                'concepto_cobro_id' => $concepto->id,
                'valor' => '90.0000',
                'fecha_inicio' => '2026-02-01',
                'fecha_fin' => null,
                'alcance' => 'todo_el_edificio',
            ]));
            $this->fail('La base permitió dos tarifas abiertas para el mismo concepto.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('unique', strtolower($exception->getMessage()));
        }
        try {
            DB::transaction(static fn () => DB::table('tarifa_departamentos')->insert([
                'tarifa_id' => $tarifa->id,
                'edificio_id' => $firstBuilding->id,
                'departamento_id' => $departamentos[0]->id,
            ]));
            $this->fail('La base permitió relacionar el departamento de otro edificio.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
        }
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel, list<DepartamentoEloquentModel>} */
    private function buildingFixture(int $departmentCount = 1): array
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
        $piso = PisoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'torre_id' => $edificio->torres()->sole()->id,
            'numero' => '1',
            'nombre' => 'Primer piso',
            'orden' => 1,
            'estado' => 'activo',
        ]);
        $departamentos = [];
        for ($index = 1; $index <= $departmentCount; $index++) {
            $departamentos[] = DepartamentoEloquentModel::query()->forceCreate([
                'edificio_id' => $edificio->id,
                'piso_id' => $piso->id,
                'codigo' => 'A-10'.$index,
                'nombre' => 'Departamento A-10'.$index,
                'alicuota' => '5.000000',
                'estado' => 'activo',
            ]);
        }

        return [$user, $edificio, $departamentos];
    }

    /** @param array<string, mixed> $overrides */
    private function createConcept(EdificioEloquentModel $edificio, string $codigo, array $overrides = []): ConceptoCobroEloquentModel
    {
        return ConceptoCobroEloquentModel::query()->forceCreate(array_merge([
            'edificio_id' => $edificio->id,
            'codigo' => $codigo,
            'nombre' => 'Concepto '.$codigo,
            'descripcion' => null,
            'tipo' => 'ordinario',
            'periodicidad' => 'mensual',
            'forma_calculo' => 'por_alicuota',
            'estado' => 'activo',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function conceptData(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'ALICUOTA',
            'nombre' => 'Alícuota ordinaria',
            'descripcion' => 'Presupuesto mensual distribuido por alícuota.',
            'tipo' => 'ordinario',
            'periodicidad' => 'mensual',
            'forma_calculo' => 'por_alicuota',
            'estado' => 'activo',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function rateData(array $overrides = []): array
    {
        return array_merge([
            'valor' => '100.0000',
            'porcentaje' => null,
            'monto_total' => null,
            'numero_cuotas' => null,
            'unidad' => null,
            'base_calculo' => null,
            'fecha_inicio' => CarbonImmutable::today()->format('Y-m-d'),
            'fecha_fin' => null,
            'alcance' => 'todo_el_edificio',
            'departamentos' => [],
            'observacion' => null,
        ], $overrides);
    }
}
