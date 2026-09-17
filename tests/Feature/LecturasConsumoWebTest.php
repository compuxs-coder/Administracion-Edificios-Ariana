<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Finanzas\Infrastructure\Models\AplicacionPagoEloquentModel;
use Src\Finanzas\Infrastructure\Models\CargoEloquentModel;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;
use Src\Finanzas\Infrastructure\Models\LecturaConsumoEloquentModel;
use Src\Finanzas\Infrastructure\Models\PagoEloquentModel;
use Src\Finanzas\Infrastructure\Models\TarifaConceptoEloquentModel;
use Tests\TestCase;

final class LecturasConsumoWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_require_authentication_and_no_mutation_routes_exist(): void
    {
        [, $edificio, $departamentos] = $this->fixture();
        $concepto = $this->consumptionConcept($edificio);

        $this->get(route('lecturas.index'))->assertRedirect(route('login'));
        $this->get(route('lecturas.create'))->assertRedirect(route('login'));
        $this->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto))->assertRedirect(route('login'));
        $this->assertFalse(Route::has('lecturas.update'));
        $this->assertFalse(Route::has('lecturas.destroy'));
        $this->assertDatabaseCount('lecturas_consumo', 0);
    }

    public function test_user_registers_and_queries_accumulative_readings_with_server_calculated_consumption(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture();
        $concepto = $this->consumptionConcept($edificio);
        $this->rate($edificio, $concepto, ['valor' => '2.0000', 'unidad' => 'm3']);

        $this->actingAs($user)
            ->get(route('lecturas.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('LecturaConsumo/create')
                ->has('edificios', 1)
                ->has('departamentos', 1)
                ->has('conceptos', 1)
                ->has('ultimasLecturas', 0));

        $this->actingAs($user)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto, [
                'consumo' => '9999.0000',
            ]))
            ->assertSessionHasNoErrors();
        $primera = LecturaConsumoEloquentModel::query()->sole();
        $this->assertSame('100.0000', $primera->lectura_anterior);
        $this->assertSame('112.5000', $primera->lectura_actual);
        $this->assertSame('12.5000', $primera->consumo);
        $this->assertSame('m3', $primera->unidad);

        $this->actingAs($user)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto, [
                'periodo' => '2026-09',
                'fecha_lectura' => '2026-09-30',
                'lectura_anterior' => '112.5000',
                'lectura_actual' => '120.0000',
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get(route('lecturas.index', ['edificio_id' => $edificio->id, 'periodo' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('LecturaConsumo/index')
                ->has('lecturas.data', 1)
                ->where('lecturas.data.0.lecturaAnterior', '112.5000')
                ->where('lecturas.data.0.lecturaActual', '120.0000')
                ->where('lecturas.data.0.consumo', '7.5000')
                ->where('lecturas.data.0.unidad', 'm3'));

        $edificio->update(['estado' => 'inactivo']);
        $departamentos[0]->update(['estado' => 'inactivo']);
        $concepto->update(['estado' => 'inactivo']);
        $this->actingAs($user)
            ->get(route('lecturas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('edificios', 1)
                ->has('departamentos', 1)
                ->has('conceptos', 1));
    }

    public function test_reading_validation_rejects_regressions_broken_continuity_duplicates_and_invalid_dates(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture();
        $concepto = $this->consumptionConcept($edificio);
        $this->rate($edificio, $concepto, ['fecha_fin' => '2026-09-01']);
        $this->rate($edificio, $concepto, ['unidad' => 'kWh', 'fecha_inicio' => '2026-09-01']);

        $this->actingAs($user)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto, [
                'lectura_actual' => '99.9999',
            ]))
            ->assertSessionHasErrors('lecturaActual');
        $this->actingAs($user)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto, [
                'fecha_lectura' => '2026-09-01',
            ]))
            ->assertSessionHasErrors('fechaLectura');

        $this->actingAs($user)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto))
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto))
            ->assertSessionHasErrors('periodo');
        $this->actingAs($user)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto, [
                'periodo' => '2026-10',
                'fecha_lectura' => '2026-10-31',
                'lectura_anterior' => '110.0000',
                'lectura_actual' => '120.0000',
            ]))
            ->assertSessionHasErrors('lecturaAnterior');
        $this->actingAs($user)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto, [
                'periodo' => '2026-09',
                'fecha_lectura' => '2026-09-30',
                'lectura_anterior' => '112.5000',
                'lectura_actual' => '120.0000',
            ]))
            ->assertSessionHasErrors('conceptoCobroId');

        $this->assertDatabaseCount('lecturas_consumo', 1);
    }

    public function test_reading_access_is_scoped_by_building_and_rbac_prevents_privilege_escalation(): void
    {
        [$administrator, $edificio, $departamentos] = $this->fixture();
        [$otherAdministrator, $otroEdificio, $otrosDepartamentos] = $this->fixture();
        $concepto = $this->consumptionConcept($edificio, 'AGUA');
        $otroConcepto = $this->consumptionConcept($otroEdificio, 'AGUA');
        $this->rate($edificio, $concepto);
        $this->rate($otroEdificio, $otroConcepto);
        $financeManager = UserEloquentModel::factory()->create();
        $viewer = UserEloquentModel::factory()->create();
        $propertyManager = UserEloquentModel::factory()->create();
        $this->addMember($edificio, $financeManager, RolEdificio::GESTOR_FINANZAS);
        $this->addMember($otroEdificio, $financeManager, RolEdificio::CONSULTA);
        $this->addMember($edificio, $viewer, RolEdificio::CONSULTA);
        $this->addMember($edificio, $propertyManager, RolEdificio::GESTOR_PROPIEDAD);

        $this->actingAs($financeManager)
            ->get(route('lecturas.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('edificios', 1)
                ->where('edificios.0.id', $edificio->id));
        $this->actingAs($financeManager)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto))
            ->assertSessionHasNoErrors();
        $this->actingAs($financeManager)
            ->post(route('lecturas.store', $otroEdificio), $this->readingData($otrosDepartamentos[0], $otroConcepto))
            ->assertForbidden();

        $this->actingAs($viewer)->get(route('lecturas.index'))->assertOk();
        $this->actingAs($viewer)->get(route('lecturas.create'))->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto, ['periodo' => '2026-09', 'fecha_lectura' => '2026-09-30']))
            ->assertForbidden();
        $this->actingAs($propertyManager)->get(route('lecturas.index'))->assertForbidden();

        $this->actingAs($administrator)
            ->post(route('lecturas.store', $edificio), $this->readingData($otrosDepartamentos[0], $concepto, ['periodo' => '2026-09', 'fecha_lectura' => '2026-09-30']))
            ->assertSessionHasErrors('departamentoId');
        $this->actingAs($administrator)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $otroConcepto, ['periodo' => '2026-09', 'fecha_lectura' => '2026-09-30']))
            ->assertSessionHasErrors('conceptoCobroId');

        $this->actingAs($otherAdministrator)
            ->get(route('lecturas.index'))
            ->assertInertia(fn (Assert $page) => $page->has('lecturas.data', 0));
        $this->assertDatabaseCount('lecturas_consumo', 1);
    }

    public function test_database_guards_cross_building_links_invalid_values_and_history_mutation(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture();
        [, $otroEdificio, $otrosDepartamentos] = $this->fixture();
        $concepto = $this->consumptionConcept($edificio);
        $this->rate($edificio, $concepto);
        $this->actingAs($user)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto))
            ->assertSessionHasNoErrors();
        $lectura = LecturaConsumoEloquentModel::query()->sole();

        $this->assertDatabaseRejects(static fn () => DB::table('lecturas_consumo')->insert([
            'id' => (string) Str::uuid(),
            'edificio_id' => $edificio->id,
            'departamento_id' => $otrosDepartamentos[0]->id,
            'concepto_cobro_id' => $concepto->id,
            'periodo' => '2026-09-01',
            'fecha_lectura' => '2026-09-30',
            'lectura_anterior' => '112.5000',
            'lectura_actual' => '120.0000',
            'consumo' => '7.5000',
            'unidad' => 'm3',
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('lecturas_consumo')->insert([
            'id' => (string) Str::uuid(),
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamentos[0]->id,
            'concepto_cobro_id' => $concepto->id,
            'periodo' => '2026-09-01',
            'fecha_lectura' => '2026-09-30',
            'lectura_anterior' => '112.5000',
            'lectura_actual' => '120.0000',
            'consumo' => '99.0000',
            'unidad' => 'm3',
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('lecturas_consumo')->insert([
            'id' => (string) Str::uuid(),
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamentos[0]->id,
            'concepto_cobro_id' => $concepto->id,
            'periodo' => 'fecha-invalida',
            'fecha_lectura' => 'fecha-invalida',
            'lectura_anterior' => '112.5000',
            'lectura_actual' => '120.0000',
            'consumo' => '7.5000',
            'unidad' => 'm3',
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('lecturas_consumo')->insert([
            'id' => (string) Str::uuid(),
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamentos[0]->id,
            'concepto_cobro_id' => $concepto->id,
            'periodo' => '2026-09-01',
            'fecha_lectura' => '2026-09-30',
            'lectura_anterior' => 'abc',
            'lectura_actual' => 'abc',
            'consumo' => 'abc',
            'unidad' => 'm3',
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('lecturas_consumo')->where('id', $lectura->id)->update(['lectura_actual' => '999.0000']));
        $this->assertDatabaseRejects(static fn () => DB::table('lecturas_consumo')->where('id', $lectura->id)->delete());

        $this->assertDatabaseHas('lecturas_consumo', [
            'id' => $lectura->id,
            'lectura_actual' => '112.5',
            'consumo' => '12.5',
        ]);
        $this->assertNotSame($edificio->id, $otroEdificio->id);
    }

    public function test_consumption_generation_uses_period_reading_and_freezes_reference_and_snapshot(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture(2);
        $concepto = $this->consumptionConcept($edificio);
        $this->rate($edificio, $concepto, ['valor' => '2.0000', 'unidad' => 'm3']);
        $this->actingAs($user)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamentos[0], $concepto))
            ->assertSessionHasNoErrors();
        $lectura = LecturaConsumoEloquentModel::query()->sole();

        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-08', 'concepto_cobro_id' => $concepto->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.cantidadCargos', 1)
                ->where('preview.cantidadOmitidos', 1)
                ->where('preview.cargos.0.base', '12.5000')
                ->where('preview.cargos.0.valor', '25.0000')
                ->where('preview.cargos.0.metadata.calculo.lectura.id', $lectura->id)
                ->where('preview.advertencias.0.codigo', 'CONSUMO_NO_DISPONIBLE'));
        $this->actingAs($user)
            ->post(route('cargos.generate.store', $edificio), ['periodo' => '2026-08', 'concepto_cobro_id' => $concepto->id])
            ->assertSessionHasNoErrors();

        $cargo = CargoEloquentModel::query()->sole();
        $this->assertSame('25.0000', $cargo->valor_original);
        $this->assertSame($lectura->id, $cargo->lectura_consumo_id);
        $this->assertSame('100.0000', $cargo->metadata['calculo']['lectura']['lecturaAnterior']);
        $this->assertSame('112.5000', $cargo->metadata['calculo']['lectura']['lecturaActual']);
        $this->assertSame('2.0000', $cargo->metadata['tarifa']['valor']);

        $this->assertDatabaseRejects(static fn () => DB::table('lecturas_consumo')->where('id', $lectura->id)->update(['lectura_actual' => '130.0000']));
        $this->assertDatabaseRejects(static fn () => DB::table('cargos')->where('id', $cargo->id)->update(['lectura_consumo_id' => null]));
        $this->assertDatabaseRejects(static fn () => DB::table('cargos')->where('id', $cargo->id)->update(['metadata' => '{}']));
        $this->assertSame('25.0000', $cargo->refresh()->valor_original);

        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $concepto]), [
                ...$this->rateData(['valor' => '3.0000', 'unidad' => 'kWh', 'fecha_inicio' => '2026-09-01']),
            ])
            ->assertSessionHasErrors('unidad');
    }

    public function test_percentage_calculation_resolves_all_bases_at_period_start(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture();
        $departamento = $departamentos[0];
        $capital = $this->concept($edificio, 'CAPITAL');
        $capitalNoVencido = $this->concept($edificio, 'CAPITAL-FUTURO');
        $interesAnterior = $this->concept($edificio, 'INT-ANT', ['tipo' => 'interes', 'forma_calculo' => 'porcentaje']);
        $capitalVencido = $this->baseCargo($edificio, $departamento, $capital, '100.0000', '2026-08-01', '2026-08-31');
        $this->baseCargo($edificio, $departamento, $interesAnterior, '20.0000', '2026-08-01', '2026-08-31');
        $this->baseCargo($edificio, $departamento, $capitalNoVencido, '50.0000', '2026-08-01', '2026-09-30');
        $pago = PagoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamento->id,
            'numero' => 'PAG-2026-000001',
            'fecha_pago' => '2026-08-20',
            'monto_recibido' => '30.0000',
            'forma_pago' => 'efectivo',
            'estado' => 'registrado',
            'origen' => 'manual',
        ]);
        AplicacionPagoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamento->id,
            'pago_id' => $pago->id,
            'cargo_id' => $capitalVencido->id,
            'monto_aplicado' => '30.0000',
            'created_at' => '2026-08-20 12:00:00',
            'updated_at' => '2026-08-20 12:00:00',
        ]);
        $pago->update([
            'estado' => 'anulado',
            'anulado_por' => $user->id,
            'anulado_at' => '2026-09-05 12:00:00',
            'motivo_anulacion' => 'Anulación posterior al corte',
        ]);
        $pagoTardio = PagoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamento->id,
            'numero' => 'PAG-2026-000002',
            'fecha_pago' => '2026-08-25',
            'monto_recibido' => '10.0000',
            'forma_pago' => 'efectivo',
            'estado' => 'registrado',
            'origen' => 'manual',
        ]);
        AplicacionPagoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamento->id,
            'pago_id' => $pagoTardio->id,
            'cargo_id' => $capitalVencido->id,
            'monto_aplicado' => '10.0000',
            'created_at' => '2026-09-05 12:00:00',
            'updated_at' => '2026-09-05 12:00:00',
        ]);
        $capitalVencido->update(['saldo' => '90.0000', 'estado' => 'parcial']);

        $expected = [
            'saldo_vencido' => ['base' => '90.0000', 'valor' => '9.0000'],
            'capital_vencido' => ['base' => '70.0000', 'valor' => '7.0000'],
            'saldo_total' => ['base' => '140.0000', 'valor' => '14.0000'],
        ];
        foreach ($expected as $base => $values) {
            $concepto = $this->concept($edificio, 'PCT-'.Str::upper(str_replace('_', '-', $base)), ['tipo' => 'interes', 'forma_calculo' => 'porcentaje']);
            $this->rate($edificio, $concepto, ['valor' => null, 'porcentaje' => '10.000000', 'base_calculo' => $base]);
            $this->actingAs($user)
                ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-09', 'concepto_cobro_id' => $concepto->id]))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('preview.cantidadCargos', 1)
                    ->where('preview.cargos.0.base', $values['base'])
                    ->where('preview.cargos.0.valor', $values['valor'])
                    ->where('preview.cargos.0.metadata.calculo.baseCalculo', $base)
                    ->where('preview.cargos.0.metadata.calculo.fechaCorte', '2026-09-01'));
        }

        $porcentajeIncompleto = $this->concept($edificio, 'PCT-SIN-BASE', ['tipo' => 'multa', 'forma_calculo' => 'porcentaje']);
        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $porcentajeIncompleto]), $this->rateData([
                'valor' => null,
                'porcentaje' => '2.000000',
                'base_calculo' => null,
            ]))
            ->assertSessionHasErrors('baseCalculo');
        $extraordinarioPorcentual = $this->concept($edificio, 'PCT-CUOTAS', ['tipo' => 'extraordinario', 'forma_calculo' => 'porcentaje']);
        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $extraordinarioPorcentual]), $this->rateData([
                'valor' => null,
                'porcentaje' => '2.000000',
                'base_calculo' => 'saldo_total',
                'monto_total' => '100.0000',
                'numero_cuotas' => 2,
            ]))
            ->assertSessionHasErrors(['montoTotal', 'numeroCuotas']);
        $tarifaIncompleta = $this->rate($edificio, $porcentajeIncompleto, ['valor' => null, 'porcentaje' => '2.000000', 'base_calculo' => null]);
        $this->assertNotNull($tarifaIncompleta->id);
        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-09', 'concepto_cobro_id' => $porcentajeIncompleto->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.cantidadCargos', 0)
                ->where('preview.advertencias.0.codigo', 'BASE_PORCENTAJE_NO_DISPONIBLE'));
    }

    public function test_variable_calculations_outside_money_precision_are_omitted(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture();
        $departamento = $departamentos[0];
        $consumo = $this->consumptionConcept($edificio, 'CONSUMO-GRANDE');
        $this->rate($edificio, $consumo, ['valor' => '2.0000']);
        $this->actingAs($user)
            ->post(route('lecturas.store', $edificio), $this->readingData($departamento, $consumo, [
                'lectura_anterior' => '0.0000',
                'lectura_actual' => '6000000000.0000',
            ]))
            ->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-08', 'concepto_cobro_id' => $consumo->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.cantidadCargos', 0)
                ->where('preview.advertencias.0.codigo', 'VALOR_FUERA_DE_RANGO'));

        $capitalUno = $this->concept($edificio, 'CAPITAL-GRANDE-1');
        $capitalDos = $this->concept($edificio, 'CAPITAL-GRANDE-2');
        $this->baseCargo($edificio, $departamento, $capitalUno, '6000000000.0000', '2026-08-01', '2026-08-31');
        $this->baseCargo($edificio, $departamento, $capitalDos, '6000000000.0000', '2026-08-01', '2026-08-31');
        $porcentaje = $this->concept($edificio, 'PORCENTAJE-GRANDE', ['tipo' => 'interes', 'forma_calculo' => 'porcentaje']);
        $this->rate($edificio, $porcentaje, ['valor' => null, 'porcentaje' => '100.000000', 'base_calculo' => 'saldo_total']);
        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-09', 'concepto_cobro_id' => $porcentaje->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.cantidadCargos', 0)
                ->where('preview.advertencias.0.codigo', 'VALOR_FUERA_DE_RANGO'));
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel, list<DepartamentoEloquentModel>} */
    private function fixture(int $departmentCount = 1): array
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
                'nombre' => 'Departamento '.$index,
                'alicuota' => '5.000000',
                'estado' => 'activo',
            ]);
        }

        return [$user, $edificio, $departamentos];
    }

    private function consumptionConcept(EdificioEloquentModel $edificio, string $codigo = 'GAS'): ConceptoCobroEloquentModel
    {
        return $this->concept($edificio, $codigo, ['tipo' => 'consumo', 'forma_calculo' => 'por_consumo']);
    }

    /** @param array<string, mixed> $overrides */
    private function concept(EdificioEloquentModel $edificio, string $codigo, array $overrides = []): ConceptoCobroEloquentModel
    {
        return ConceptoCobroEloquentModel::query()->forceCreate(array_merge([
            'edificio_id' => $edificio->id,
            'codigo' => $codigo,
            'nombre' => 'Concepto '.$codigo,
            'tipo' => 'ordinario',
            'periodicidad' => 'mensual',
            'forma_calculo' => 'valor_fijo',
            'estado' => 'activo',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function rate(EdificioEloquentModel $edificio, ConceptoCobroEloquentModel $concepto, array $overrides = []): TarifaConceptoEloquentModel
    {
        return TarifaConceptoEloquentModel::query()->forceCreate(array_merge([
            'edificio_id' => $edificio->id,
            'concepto_cobro_id' => $concepto->id,
            'valor' => '1.0000',
            'porcentaje' => null,
            'monto_total' => null,
            'numero_cuotas' => null,
            'unidad' => 'm3',
            'base_calculo' => null,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => null,
            'alcance' => 'todo_el_edificio',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function readingData(DepartamentoEloquentModel $departamento, ConceptoCobroEloquentModel $concepto, array $overrides = []): array
    {
        return array_merge([
            'departamento_id' => $departamento->id,
            'concepto_cobro_id' => $concepto->id,
            'periodo' => '2026-08',
            'fecha_lectura' => '2026-08-31',
            'lectura_anterior' => '100.0000',
            'lectura_actual' => '112.5000',
            'observacion' => 'Lectura mensual',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function rateData(array $overrides = []): array
    {
        return array_merge([
            'valor' => '1.0000',
            'porcentaje' => null,
            'monto_total' => null,
            'numero_cuotas' => null,
            'unidad' => 'm3',
            'base_calculo' => null,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => null,
            'alcance' => 'todo_el_edificio',
            'departamentos' => [],
            'observacion' => null,
        ], $overrides);
    }

    private function baseCargo(
        EdificioEloquentModel $edificio,
        DepartamentoEloquentModel $departamento,
        ConceptoCobroEloquentModel $concepto,
        string $valor,
        string $emision,
        string $vencimiento,
    ): CargoEloquentModel {
        return CargoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamento->id,
            'concepto_cobro_id' => $concepto->id,
            'periodo' => substr($emision, 0, 7).'-01',
            'fecha_emision' => $emision,
            'fecha_vencimiento' => $vencimiento,
            'descripcion' => 'Cargo base',
            'valor_original' => $valor,
            'saldo' => $valor,
            'estado' => 'pendiente',
            'origen' => 'manual',
        ]);
    }

    private function addMember(EdificioEloquentModel $edificio, UserEloquentModel $user, RolEdificio $role): void
    {
        $edificio->usuarios()->attach($user->id, ['creado_por_user_id' => $edificio->usuarios()->firstOrFail()->id]);
        DB::table('edificio_usuario_roles')->insert([
            'edificio_id' => $edificio->id,
            'user_id' => $user->id,
            'rol_codigo' => $role->value,
            'asignado_por_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('La base de datos permitió una mutación protegida.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
