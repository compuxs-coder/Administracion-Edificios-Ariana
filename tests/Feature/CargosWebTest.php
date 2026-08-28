<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
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
use Src\Finanzas\Application\Actions\AutomaticCargosAction;
use Src\Finanzas\Infrastructure\Models\CargoEloquentModel;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;
use Src\Finanzas\Infrastructure\Models\TarifaConceptoEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;
use Tests\TestCase;

final class CargosWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_cargo_routes_require_authentication(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture();
        $concepto = $this->concept($edificio, 'ALIC');

        $this->get(route('cargos.index'))->assertRedirect(route('login'));
        $this->get(route('cargos.generate'))->assertRedirect(route('login'));
        $this->get(route('cargos.create'))->assertRedirect(route('login'));
        $this->post(route('cargos.generate.store', $edificio), ['periodo' => $this->period(), 'concepto_cobro_id' => $concepto->id])->assertRedirect(route('login'));
        $this->post(route('cargos.store', $edificio), $this->manualData($departamentos[0], $concepto))->assertRedirect(route('login'));
        $this->assertNotNull($user->id);
    }

    public function test_preview_and_generation_create_fixed_charges_with_snapshot_and_idempotency(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture(2);
        $concepto = $this->concept($edificio, 'ALIC', ['forma_calculo' => 'valor_fijo']);
        $this->rate($edificio, $concepto, ['valor' => '90.0000']);
        $this->owner($edificio, $departamentos[0]);

        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => $this->period(), 'concepto_cobro_id' => $concepto->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Cargo/generate')
                ->where('preview.cantidadCargos', 2)
                ->where('preview.totalValor', '180.0000'));
        $this->assertDatabaseCount('cargos', 0);

        $this->actingAs($user)
            ->post(route('cargos.generate.store', $edificio), ['periodo' => $this->period(), 'concepto_cobro_id' => $concepto->id])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cargos', 2);
        $this->assertDatabaseHas('lotes_generacion_cargos', ['edificio_id' => $edificio->id, 'cargos_creados' => 2, 'total_valor' => '180']);
        $cargo = CargoEloquentModel::query()->where('departamento_id', $departamentos[0]->id)->sole();
        $this->assertSame('90.0000', $cargo->valor_original);
        $this->assertSame('pendiente', $cargo->estado->value);
        $this->assertSame('Juan Cargo', $cargo->metadata['propietarios'][0]['nombre']);

        $this->actingAs($user)
            ->post(route('cargos.generate.store', $edificio), ['periodo' => $this->period(), 'concepto_cobro_id' => $concepto->id])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cargos', 2);
        $this->assertDatabaseHas('lotes_generacion_cargos', ['edificio_id' => $edificio->id, 'cargos_creados' => 0, 'cargos_omitidos' => 2]);
    }

    public function test_generation_calculates_by_alicuota_and_selects_the_rate_valid_for_period(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture(2, ['3.250000', '1.000000']);
        $concepto = $this->concept($edificio, 'PRESUPUESTO', ['forma_calculo' => 'por_alicuota']);
        $periodo = CarbonImmutable::createFromFormat('!Y-m', $this->period());
        $this->rate($edificio, $concepto, ['valor' => '8000.0000', 'fecha_inicio' => $periodo->subMonth(), 'fecha_fin' => $periodo]);
        $this->rate($edificio, $concepto, ['valor' => '10000.0000', 'fecha_inicio' => $periodo]);

        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => $this->period(), 'concepto_cobro_id' => $concepto->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.cargos.0.valor', '325.0000')
                ->where('preview.cargos.1.valor', '100.0000')
            ->where('preview.cargos.0.base', '10000.0000'));
    }

    public function test_extraordinary_alicuota_installments_follow_the_rate_start(): void
    {
        [$user, $edificio] = $this->fixture(2, ['60.000000', '40.000000']);
        $concepto = $this->concept($edificio, 'OBRA-ALIC', ['tipo' => 'extraordinario', 'periodicidad' => 'trimestral', 'forma_calculo' => 'por_alicuota']);
        $inicio = CarbonImmutable::createFromFormat('!Y-m', '2026-02');
        $this->rate($edificio, $concepto, ['valor' => '1200.0000', 'monto_total' => '1200.0000', 'numero_cuotas' => 2, 'fecha_inicio' => $inicio]);

        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-02', 'concepto_cobro_id' => $concepto->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.cargos.0.valor', '360.0000')
                ->where('preview.cargos.1.valor', '240.0000')
                ->where('preview.totalValor', '600.0000'));
        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-05', 'concepto_cobro_id' => $concepto->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.cargos.0.metadata.calculo.cuota', 1)
                ->where('preview.totalValor', '600.0000'));
        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-08', 'concepto_cobro_id' => $concepto->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.cantidadCargos', 0)
                ->where('preview.cantidadOmitidos', 2)
                ->where('preview.advertencias.0.codigo', 'CUOTAS_COMPLETADAS'));
    }

    public function test_generation_respects_scope_inactive_departments_and_missing_or_non_generable_data(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture(2);
        $specific = $this->concept($edificio, 'OBRA', ['tipo' => 'extraordinario', 'forma_calculo' => 'valor_fijo']);
        $this->rate($edificio, $specific, ['valor' => '50.0000', 'alcance' => 'departamentos_especificos'], [$departamentos[0]]);
        $departamentos[1]->update(['estado' => 'inactivo']);
        $gas = $this->concept($edificio, 'GAS', ['tipo' => 'consumo', 'forma_calculo' => 'por_consumo']);
        $this->rate($edificio, $gas, ['valor' => '0.2500', 'unidad' => 'm3']);
        $porcentaje = $this->concept($edificio, 'INTERES', ['tipo' => 'interes', 'forma_calculo' => 'porcentaje']);
        $this->rate($edificio, $porcentaje, ['porcentaje' => '1.000000', 'valor' => null, 'base_calculo' => 'saldo_vencido']);

        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => $this->period()]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.cantidadCargos', 1)
                ->where('preview.cargos.0.departamentoId', $departamentos[0]->id)
                ->where('preview.cantidadOmitidos', 2)
                ->has('preview.advertencias', 2));
    }

    public function test_extraordinary_installments_manual_charge_and_cancellation_are_safe(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture();
        $extra = $this->concept($edificio, 'ASCENSOR', ['tipo' => 'extraordinario', 'forma_calculo' => 'valor_fijo']);
        $this->rate($edificio, $extra, ['valor' => '200.0000', 'monto_total' => '1200.0000', 'numero_cuotas' => 6]);
        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => $this->period(), 'concepto_cobro_id' => $extra->id]))
            ->assertInertia(fn (Assert $page) => $page->where('preview.cargos.0.valor', '200.0000'));
        $finCuotas = CarbonImmutable::createFromFormat('!Y-m', $this->period())->addMonths(6)->format('Y-m');
        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => $finCuotas, 'concepto_cobro_id' => $extra->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.cantidadCargos', 0)
                ->where('preview.advertencias.0.codigo', 'CUOTAS_COMPLETADAS'));

        $manual = $this->concept($edificio, 'MULTA', ['tipo' => 'multa', 'periodicidad' => 'manual', 'forma_calculo' => 'valor_fijo']);
        $this->actingAs($user)
            ->post(route('cargos.store', $edificio), $this->manualData($departamentos[0], $manual, ['valor' => '35.5000']))
            ->assertSessionHasNoErrors();
        $cargo = CargoEloquentModel::query()->sole();
        $this->assertSame('manual', $cargo->origen->value);
        $this->actingAs($user)
            ->patch(route('cargos.cancel', [$edificio, $cargo]), ['motivo' => 'Registro duplicado'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cargos', ['id' => $cargo->id, 'estado' => 'anulado', 'motivo_anulacion' => 'Registro duplicado']);
        $this->assertFalse(Route::has('cargos.destroy'));
    }

    public function test_dry_run_command_does_not_persist_and_unauthorized_users_cannot_generate_or_cancel(): void
    {
        [$owner, $edificio, $departamentos] = $this->fixture();
        [$other] = $this->fixture();
        $concepto = $this->concept($edificio, 'ALIC', ['forma_calculo' => 'valor_fijo']);
        $this->rate($edificio, $concepto, ['valor' => '40.0000']);

        $this->artisan('finanzas:generar-cargos', ['--edificio' => $edificio->id, '--periodo' => $this->period(), '--dry-run' => true])
            ->assertExitCode(0);
        $this->assertDatabaseCount('cargos', 0);
        $this->actingAs($other)
            ->post(route('cargos.generate.store', $edificio), ['periodo' => $this->period(), 'concepto_cobro_id' => $concepto->id])
            ->assertForbidden();

        $this->actingAs($owner)
            ->post(route('cargos.store', $edificio), $this->manualData($departamentos[0], $concepto))
            ->assertSessionHasNoErrors();
        $cargo = CargoEloquentModel::query()->sole();
        $this->actingAs($other)->patch(route('cargos.cancel', [$edificio, $cargo]), ['motivo' => 'No autorizado'])->assertForbidden();
    }

    public function test_command_concept_filter_targets_its_building_and_dry_run_reflects_idempotency(): void
    {
        [, $edificio] = $this->fixture();
        $this->fixture();
        $concepto = $this->concept($edificio, 'CMD-ALIC');
        $this->rate($edificio, $concepto, ['valor' => '40.0000']);

        $this->artisan('finanzas:generar-cargos', ['--periodo' => $this->period(), '--concepto' => $concepto->id])
            ->assertExitCode(0);
        $this->assertDatabaseCount('cargos', 1);
        /** @var AutomaticCargosAction $automatic */
        $automatic = $this->app->make(AutomaticCargosAction::class);
        $preview = $automatic->execute($this->period(), null, $concepto->id, true);
        $this->assertCount(1, $preview);
        $this->assertSame(0, $preview[0]['cantidadCargos']);
        $this->assertSame(1, $preview[0]['cantidadOmitidos']);

        $this->artisan('finanzas:generar-cargos', ['--periodo' => $this->period(), '--concepto' => $concepto->id, '--dry-run' => true])
            ->assertExitCode(0);
        $this->assertDatabaseCount('cargos', 1);
    }

    public function test_periodicities_manual_concepts_and_missing_rates_are_handled_without_invalid_charges(): void
    {
        [$user, $edificio] = $this->fixture();
        $trimestral = $this->concept($edificio, 'TRIMESTRAL', ['periodicidad' => 'trimestral']);
        $manual = $this->concept($edificio, 'MANUAL', ['periodicidad' => 'manual']);
        $sinTarifa = $this->concept($edificio, 'SIN-TARIFA');
        $enero = CarbonImmutable::createFromFormat('!Y-m', '2026-01');
        $this->rate($edificio, $trimestral, ['fecha_inicio' => $enero]);
        $this->rate($edificio, $manual, ['fecha_inicio' => $enero]);

        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-01']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.cantidadCargos', 1)
                ->where('preview.cargos.0.conceptoId', $trimestral->id)
                ->where('preview.advertencias.0.codigo', 'SIN_TARIFA_VIGENTE'));
        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-02', 'concepto_cobro_id' => $trimestral->id]))
            ->assertInertia(fn (Assert $page) => $page->where('preview.cantidadCargos', 0));
        $this->assertDatabaseCount('cargos', 0);
        $this->assertNotNull($sinTarifa->id);
    }

    public function test_periodicities_are_anchored_to_the_tariff_start_and_multi_installments_require_recurring_generation(): void
    {
        [$user, $edificio] = $this->fixture();
        $inicio = CarbonImmutable::createFromFormat('!Y-m', '2026-02');
        $semestral = $this->concept($edificio, 'SEMESTRAL', ['periodicidad' => 'semestral']);
        $anual = $this->concept($edificio, 'ANUAL', ['periodicidad' => 'anual']);
        $unico = $this->concept($edificio, 'UNICO', ['periodicidad' => 'unico']);
        $this->rate($edificio, $semestral, ['fecha_inicio' => $inicio]);
        $this->rate($edificio, $anual, ['fecha_inicio' => $inicio]);
        $this->rate($edificio, $unico, ['fecha_inicio' => $inicio]);

        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-08', 'concepto_cobro_id' => $semestral->id]))
            ->assertInertia(fn (Assert $page) => $page->where('preview.cantidadCargos', 1));
        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2027-02', 'concepto_cobro_id' => $anual->id]))
            ->assertInertia(fn (Assert $page) => $page->where('preview.cantidadCargos', 1));
        $this->actingAs($user)
            ->get(route('cargos.generate', ['edificio_id' => $edificio->id, 'periodo' => '2026-02', 'concepto_cobro_id' => $unico->id]))
            ->assertInertia(fn (Assert $page) => $page->where('preview.cantidadCargos', 1));
        $this->actingAs($user)
            ->post(route('conceptos.tarifas.store', [$edificio, $unico]), [
                'valor' => '100.0000',
                'monto_total' => '100.0000',
                'numero_cuotas' => 2,
                'fecha_inicio' => '2026-03-01',
                'alcance' => 'todo_el_edificio',
            ])
            ->assertSessionHasErrors('numeroCuotas');
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel, list<DepartamentoEloquentModel>} */
    private function fixture(int $count = 1, array $alicuotas = ['5.000000']): array
    {
        $user = UserEloquentModel::factory()->create();
        /** @var CreateEdificioAction $action */
        $action = $this->app->make(CreateEdificioAction::class);
        $created = $action->execute(['nombre' => 'Edificio '.Str::upper(Str::random(6)), 'ruc' => Str::upper(Str::random(13)), 'direccion' => 'Av. Principal 123', 'ciudad' => 'Quito', 'telefono' => null, 'correo' => null, 'responsable' => null], $user->id);
        $edificio = EdificioEloquentModel::query()->findOrFail($created->id());
        $piso = PisoEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'torre_id' => $edificio->torres()->sole()->id, 'numero' => '1', 'nombre' => 'Primer piso', 'orden' => 1, 'estado' => 'activo']);
        $departamentos = [];
        for ($index = 0; $index < $count; $index++) {
            $departamentos[] = DepartamentoEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'piso_id' => $piso->id, 'codigo' => 'A-10'.($index + 1), 'nombre' => 'Departamento '.($index + 1), 'alicuota' => $alicuotas[$index] ?? '5.000000', 'estado' => 'activo']);
        }

        return [$user, $edificio, $departamentos];
    }

    /** @param array<string, mixed> $overrides */
    private function concept(EdificioEloquentModel $edificio, string $codigo, array $overrides = []): ConceptoCobroEloquentModel
    {
        return ConceptoCobroEloquentModel::query()->forceCreate(array_merge(['edificio_id' => $edificio->id, 'codigo' => $codigo, 'nombre' => 'Concepto '.$codigo, 'tipo' => 'ordinario', 'periodicidad' => 'mensual', 'forma_calculo' => 'valor_fijo', 'estado' => 'activo'], $overrides));
    }

    /** @param array<string, mixed> $overrides @param list<DepartamentoEloquentModel> $departamentos */
    private function rate(EdificioEloquentModel $edificio, ConceptoCobroEloquentModel $concepto, array $overrides = [], array $departamentos = []): TarifaConceptoEloquentModel
    {
        $periodo = CarbonImmutable::createFromFormat('!Y-m', $this->period());
        $tarifa = TarifaConceptoEloquentModel::query()->forceCreate(array_merge(['edificio_id' => $edificio->id, 'concepto_cobro_id' => $concepto->id, 'valor' => '100.0000', 'porcentaje' => null, 'monto_total' => null, 'numero_cuotas' => null, 'unidad' => null, 'base_calculo' => null, 'fecha_inicio' => $periodo, 'fecha_fin' => null, 'alcance' => $departamentos === [] ? 'todo_el_edificio' : 'departamentos_especificos'], $overrides));
        foreach ($departamentos as $departamento) {
            DB::table('tarifa_departamentos')->insert(['tarifa_id' => $tarifa->id, 'edificio_id' => $edificio->id, 'departamento_id' => $departamento->id]);
        }

        return $tarifa;
    }

    private function owner(EdificioEloquentModel $edificio, DepartamentoEloquentModel $departamento): void
    {
        $owner = PropietarioEloquentModel::query()->forceCreate(['tipo_persona' => 'persona_natural', 'nombres' => 'Juan', 'apellidos' => 'Cargo', 'tipo_identificacion' => 'cedula', 'identificacion' => Str::random(10), 'estado' => 'activo']);
        $owner->edificios()->attach($edificio->id);
        DepartamentoPropietarioEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'departamento_id' => $departamento->id, 'propietario_id' => $owner->id, 'nombre_propietario' => 'Juan Cargo', 'tipo_identificacion_snapshot' => 'cedula', 'identificacion_snapshot' => $owner->identificacion, 'porcentaje' => '100.000000', 'fecha_inicio' => CarbonImmutable::createFromFormat('!Y-m', $this->period()), 'fecha_fin' => null, 'estado' => 'activa']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function manualData(DepartamentoEloquentModel $departamento, ConceptoCobroEloquentModel $concepto, array $overrides = []): array
    {
        $today = CarbonImmutable::today();

        return array_merge(['departamento_id' => $departamento->id, 'concepto_cobro_id' => $concepto->id, 'periodo' => $this->period(), 'fecha_emision' => $today->format('Y-m-d'), 'fecha_vencimiento' => $today->endOfMonth()->format('Y-m-d'), 'valor' => '30.0000', 'descripcion' => 'Cargo manual de prueba'], $overrides);
    }

    private function period(): string
    {
        return CarbonImmutable::today()->format('Y-m');
    }
}
