<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Finanzas\Domain\Enums\EstadoCargo;
use Src\Finanzas\Domain\Contracts\PagoRepositoryInterface;
use Src\Finanzas\Infrastructure\Models\AplicacionPagoEloquentModel;
use Src\Finanzas\Infrastructure\Models\CargoEloquentModel;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;
use Src\Finanzas\Infrastructure\Models\PagoEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;
use Tests\TestCase;

final class PagosWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_pago_routes_require_authentication_and_no_destructive_route_exists(): void
    {
        [, $edificio, $departamentos] = $this->fixture();

        $this->get(route('pagos.index'))->assertRedirect(route('login'));
        $this->get(route('pagos.create'))->assertRedirect(route('login'));
        $this->get(route('cartera.index'))->assertRedirect(route('login'));
        $this->post(route('pagos.store', $edificio), $this->paymentData($departamentos[0]))->assertRedirect(route('login'));
        $this->assertFalse(Route::has('pagos.destroy'));
        $this->assertFalse(Route::has('pagos.update'));
    }

    public function test_payment_preview_captures_owner_and_registers_partial_then_complete_payment(): void
    {
        [$user, $edificio, $departamentos, $concepto] = $this->fixture();
        $this->owner($edificio, $departamentos[0]);
        $cargo = $this->cargo($edificio, $departamentos[0], $concepto, '100.0000', '2026-01-31');

        $this->actingAs($user)
            ->get(route('pagos.create', ['edificio_id' => $edificio->id, 'departamento_id' => $departamentos[0]->id, 'fecha_pago' => '2026-02-01', 'valor_recibido' => '40.0000']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Pago/create')
                ->where('preview.saldoPendiente', '100.0000')
                ->where('preview.valorAplicado', '40.0000')
                ->where('preview.cargos.0.saldoPosterior', '60.0000')
                ->where('preview.propietarios.0.nombre', 'Ana Pago'));
        $this->actingAs($user)->post(route('pagos.store', $edificio), $this->paymentData($departamentos[0], ['valor_recibido' => '40.0000']))->assertSessionHasNoErrors();
        $cargo->refresh();
        $pago = PagoEloquentModel::query()->sole();
        $this->assertSame('60.0000', $cargo->saldo);
        $this->assertSame(EstadoCargo::PARCIAL, $cargo->estado);
        $this->assertSame('PAG-2026-000001', $pago->numero);
        $this->assertSame('Ana Pago', $pago->metadata['propietarios'][0]['nombre']);
        $this->assertDatabaseHas('aplicaciones_pago', ['pago_id' => $pago->id, 'cargo_id' => $cargo->id, 'monto_aplicado' => '40']);
        $this->actingAs($user)->get(route('cargos.show', [$edificio, $cargo]))->assertInertia(fn (Assert $page) => $page
            ->where('cargo.aplicacionesPago.0.numeroPago', 'PAG-2026-000001')
            ->where('cargo.aplicacionesPago.0.valorAplicado', '40.0000'));

        $this->actingAs($user)->post(route('pagos.store', $edificio), $this->paymentData($departamentos[0], ['valor_recibido' => '60.0000']))->assertSessionHasNoErrors();
        $cargo->refresh();
        $this->assertSame('0.0000', $cargo->saldo);
        $this->assertSame(EstadoCargo::PAGADO, $cargo->estado);
        $this->assertDatabaseCount('aplicaciones_pago', 2);
    }

    public function test_payment_applies_to_multiple_oldest_charges_and_ignores_paid_or_cancelled_charges(): void
    {
        [$user, $edificio, $departamentos, $concepto] = $this->fixture();
        $enero = $this->cargo($edificio, $departamentos[0], $concepto, '80.0000', '2026-01-31');
        $febrero = $this->cargo($edificio, $departamentos[0], $concepto, '80.0000', '2026-02-28');
        $marzo = $this->cargo($edificio, $departamentos[0], $concepto, '80.0000', '2026-03-31');
        $pagado = $this->cargo($edificio, $departamentos[0], $concepto, '20.0000', '2025-11-30', '0.0000', EstadoCargo::PAGADO);
        $anulado = $this->cargo($edificio, $departamentos[0], $concepto, '20.0000', '2025-12-31', '20.0000', EstadoCargo::ANULADO);

        $this->actingAs($user)->post(route('pagos.store', $edificio), $this->paymentData($departamentos[0], ['valor_recibido' => '200.0000']))->assertSessionHasNoErrors();
        $enero->refresh();
        $febrero->refresh();
        $marzo->refresh();
        $pagado->refresh();
        $anulado->refresh();
        $this->assertSame('0.0000', $enero->saldo);
        $this->assertSame('0.0000', $febrero->saldo);
        $this->assertSame('40.0000', $marzo->saldo);
        $this->assertSame(EstadoCargo::PARCIAL, $marzo->estado);
        $this->assertSame('0.0000', $pagado->saldo);
        $this->assertSame(EstadoCargo::ANULADO, $anulado->estado);
        $this->assertDatabaseCount('aplicaciones_pago', 3);
    }

    public function test_overpayment_creates_credit_that_can_be_applied_later(): void
    {
        [$user, $edificio, $departamentos, $concepto] = $this->fixture();
        $first = $this->cargo($edificio, $departamentos[0], $concepto, '100.0000', '2026-01-31');

        $this->actingAs($user)->post(route('pagos.store', $edificio), $this->paymentData($departamentos[0], ['valor_recibido' => '150.0000']))->assertSessionHasNoErrors();
        $pago = PagoEloquentModel::query()->sole();
        $first->refresh();
        $this->assertSame(EstadoCargo::PAGADO, $first->estado);
        $this->actingAs($user)->get(route('pagos.show', [$edificio, $pago]))->assertInertia(fn (Assert $page) => $page->where('pago.saldoFavor', '50.0000'));

        $future = $this->cargo($edificio, $departamentos[0], $concepto, '30.0000', '2026-02-28');
        $this->actingAs($user)->post(route('pagos.apply-credit', [$edificio, $pago]))->assertSessionHasNoErrors();
        $future->refresh();
        $this->assertSame(EstadoCargo::PAGADO, $future->estado);
        $this->actingAs($user)->get(route('pagos.show', [$edificio, $pago]))->assertInertia(fn (Assert $page) => $page
            ->where('pago.valorAplicado', '130.0000')
            ->where('pago.saldoFavor', '20.0000')
            ->has('pago.aplicaciones', 2));
    }

    public function test_payment_cancellation_preserves_history_and_restores_cargo_once(): void
    {
        [$user, $edificio, $departamentos, $concepto] = $this->fixture();
        $cargo = $this->cargo($edificio, $departamentos[0], $concepto, '100.0000', '2026-01-31');
        $this->actingAs($user)->post(route('pagos.store', $edificio), $this->paymentData($departamentos[0], ['valor_recibido' => '100.0000']))->assertSessionHasNoErrors();
        $pago = PagoEloquentModel::query()->sole();

        $this->actingAs($user)->patch(route('pagos.cancel', [$edificio, $pago]), ['motivo' => 'Transferencia rechazada'])->assertSessionHasNoErrors();
        $cargo->refresh();
        $pago->refresh();
        $this->assertSame('100.0000', $cargo->saldo);
        $this->assertSame(EstadoCargo::PENDIENTE, $cargo->estado);
        $this->assertSame('anulado', $pago->estado->value);
        $this->assertSame('Transferencia rechazada', $pago->motivo_anulacion);
        $this->assertDatabaseCount('aplicaciones_pago', 1);
        $this->actingAs($user)->patch(route('pagos.cancel', [$edificio, $pago]), ['motivo' => 'Otra vez'])->assertSessionHasErrors('estado');
    }

    public function test_payment_owner_filter_uses_the_immutable_copropiedad_snapshot(): void
    {
        [$user, $edificio, $departamentos] = $this->fixture();
        $this->owner($edificio, $departamentos[0], 'Ana', 'Pago', '50.000000');
        $copropietario = $this->owner($edificio, $departamentos[0], 'Bruno', 'Pago', '50.000000');

        $this->actingAs($user)->post(route('pagos.store', $edificio), $this->paymentData($departamentos[0], ['valor_recibido' => '10.0000']))->assertSessionHasNoErrors();
        $this->actingAs($user)->get(route('pagos.index', ['propietario_id' => $copropietario->id]))->assertInertia(fn (Assert $page) => $page
            ->has('pagos.data', 1)
            ->where('pagos.data.0.propietario', 'Ana Pago, Bruno Pago'));
    }

    public function test_cartera_separates_overdue_balances_and_statement_has_initial_and_running_balance(): void
    {
        [$user, $edificio, $departamentos, $concepto] = $this->fixture();
        $this->cargo($edificio, $departamentos[0], $concepto, '100.0000', '2026-01-31');
        $this->actingAs($user)->post(route('pagos.store', $edificio), $this->paymentData($departamentos[0], ['valor_recibido' => '40.0000']))->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('cartera.index', ['fecha' => '2026-02-15', 'situacion' => 'vencidos']))->assertInertia(fn (Assert $page) => $page
            ->where('cartera.summary.saldoVencido', '60.0000')
            ->where('cartera.data.0.estado', 'moroso')
            ->where('cartera.data.0.diasAtraso', 15));
        $this->actingAs($user)->get(route('cartera.show', ['edificio' => $edificio, 'departamento' => $departamentos[0], 'fecha_desde' => '2026-02-01', 'fecha_hasta' => '2026-02-28']))->assertInertia(fn (Assert $page) => $page
            ->component('Cartera/show')
            ->where('estadoCuenta.saldoInicial.neto', '100.0000')
            ->where('estadoCuenta.movimientos.0.credito', '40.0000')
            ->where('estadoCuenta.movimientos.0.saldoAcumulado', '60.0000')
            ->where('estadoCuenta.saldoFinal.neto', '60.0000'));
    }

    public function test_cartera_paginates_filters_age_in_backend_and_dashboard_uses_its_summary(): void
    {
        [$user, $edificio, $departamentos, $concepto] = $this->fixture(4);
        $this->cargo($edificio, $departamentos[0], $concepto, '30.0000', '2026-03-16');
        $this->cargo($edificio, $departamentos[1], $concepto, '40.0000', '2026-02-15');
        $this->cargo($edificio, $departamentos[2], $concepto, '50.0000', '2026-01-20');
        $this->cargo($edificio, $departamentos[3], $concepto, '60.0000', '2026-01-10');

        $this->actingAs($user)->get(route('cartera.index', ['fecha' => '2026-04-15', 'antiguedad' => '31_a_60', 'per_page' => 1]))->assertInertia(fn (Assert $page) => $page
            ->where('cartera.meta.total', 1)
            ->where('cartera.meta.perPage', 1)
            ->where('cartera.data.0.departamentoId', $departamentos[1]->id)
            ->where('cartera.data.0.antiguedad', '31_a_60'));
        foreach (['1_a_30' => 0, '61_a_90' => 2, 'mas_de_90' => 3] as $bucket => $index) {
            $this->actingAs($user)->get(route('cartera.index', ['fecha' => '2026-04-15', 'antiguedad' => $bucket]))->assertInertia(fn (Assert $page) => $page
                ->where('cartera.meta.total', 1)
                ->where('cartera.data.0.departamentoId', $departamentos[$index]->id)
                ->where('cartera.data.0.antiguedad', $bucket));
        }
        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('cartera.saldoPendiente', '180.0000')
            ->where('cartera.saldoVencido', '180.0000')
            ->where('cartera.departamentosConDeuda', 4));
    }

    public function test_failed_application_rolls_back_the_payment_and_cargo_mutation(): void
    {
        [$user, $edificio, $departamentos, $concepto] = $this->fixture();
        $cargo = $this->cargo($edificio, $departamentos[0], $concepto, '100.0000', '2026-01-31');
        $dispatcher = AplicacionPagoEloquentModel::getEventDispatcher();
        AplicacionPagoEloquentModel::creating(static function (): void {
            throw new \RuntimeException('Fallo de aplicación simulado.');
        });

        try {
            /** @var PagoRepositoryInterface $pagos */
            $pagos = $this->app->make(PagoRepositoryInterface::class);
            $pagos->create($user->id, $edificio->id, $this->paymentData($departamentos[0]));
            $this->fail('El error simulado debía interrumpir la transacción.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fallo de aplicación simulado.', $exception->getMessage());
        } finally {
            AplicacionPagoEloquentModel::setEventDispatcher($dispatcher);
        }

        $cargo->refresh();
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('aplicaciones_pago', 0);
        $this->assertSame('100.0000', $cargo->saldo);
        $this->assertSame(EstadoCargo::PENDIENTE, $cargo->estado);
    }

    public function test_payment_authorization_cross_building_validation_numbers_and_cartera_are_isolated(): void
    {
        [$owner, $edificio, $departamentos, $concepto] = $this->fixture();
        [$other, $otroEdificio, $otrosDepartamentos, $otroConcepto] = $this->fixture();
        $this->cargo($edificio, $departamentos[0], $concepto, '100.0000', '2026-01-31');
        $this->cargo($otroEdificio, $otrosDepartamentos[0], $otroConcepto, '70.0000', '2026-01-31');

        $this->actingAs($other)->post(route('pagos.store', $edificio), $this->paymentData($departamentos[0]))->assertForbidden();
        $this->actingAs($owner)->post(route('pagos.store', $edificio), $this->paymentData($otrosDepartamentos[0]))->assertSessionHasErrors('departamentoId');
        $this->actingAs($owner)->post(route('pagos.store', $edificio), $this->paymentData($departamentos[0], ['valor_recibido' => '20.0000']))->assertSessionHasNoErrors();
        $this->actingAs($other)->post(route('pagos.store', $otroEdificio), $this->paymentData($otrosDepartamentos[0], ['valor_recibido' => '20.0000']))->assertSessionHasNoErrors();
        $numeros = PagoEloquentModel::query()->orderBy('numero')->pluck('numero')->all();
        $this->assertSame(['PAG-2026-000001', 'PAG-2026-000002'], $numeros);

        $this->actingAs($owner)->get(route('cartera.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Cartera/index')
            ->has('cartera.data', 1)
            ->where('cartera.data.0.departamentoId', $departamentos[0]->id)
            ->where('cartera.data.0.saldoPendiente', '80.0000'));
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel, list<DepartamentoEloquentModel>, ConceptoCobroEloquentModel} */
    private function fixture(int $count = 1): array
    {
        $user = UserEloquentModel::factory()->create();
        /** @var CreateEdificioAction $action */
        $action = $this->app->make(CreateEdificioAction::class);
        $created = $action->execute(['nombre' => 'Edificio '.Str::upper(Str::random(6)), 'ruc' => Str::upper(Str::random(13)), 'direccion' => 'Av. Principal 123', 'ciudad' => 'Quito', 'telefono' => null, 'correo' => null, 'responsable' => null], $user->id);
        $edificio = EdificioEloquentModel::query()->findOrFail($created->id());
        $piso = PisoEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'torre_id' => $edificio->torres()->sole()->id, 'numero' => '1', 'nombre' => 'Primer piso', 'orden' => 1, 'estado' => 'activo']);
        $departamentos = [];
        for ($index = 0; $index < $count; $index++) {
            $departamentos[] = DepartamentoEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'piso_id' => $piso->id, 'codigo' => 'P-10'.($index + 1), 'nombre' => 'Departamento pago '.($index + 1), 'alicuota' => '5.000000', 'estado' => 'activo']);
        }
        $concepto = ConceptoCobroEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'codigo' => 'PAGO', 'nombre' => 'Cuota de prueba', 'tipo' => 'ordinario', 'periodicidad' => 'mensual', 'forma_calculo' => 'valor_fijo', 'estado' => 'activo']);

        return [$user, $edificio, $departamentos, $concepto];
    }

    private function cargo(EdificioEloquentModel $edificio, DepartamentoEloquentModel $departamento, ConceptoCobroEloquentModel $concepto, string $valor, string $vencimiento, ?string $saldo = null, EstadoCargo $estado = EstadoCargo::PENDIENTE): CargoEloquentModel
    {
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $vencimiento);

        return CargoEloquentModel::query()->forceCreate([
            'edificio_id' => $edificio->id,
            'departamento_id' => $departamento->id,
            'concepto_cobro_id' => $concepto->id,
            'periodo' => $fecha->startOfMonth(),
            'fecha_emision' => $fecha->startOfMonth(),
            'fecha_vencimiento' => $fecha,
            'descripcion' => 'Cargo '.$vencimiento,
            'valor_original' => $valor,
            'saldo' => $saldo ?? $valor,
            'estado' => $estado,
            'origen' => 'manual',
            'metadata' => [],
        ]);
    }

    private function owner(EdificioEloquentModel $edificio, DepartamentoEloquentModel $departamento, string $nombres = 'Ana', string $apellidos = 'Pago', string $porcentaje = '100.000000'): PropietarioEloquentModel
    {
        $owner = PropietarioEloquentModel::query()->forceCreate(['tipo_persona' => 'persona_natural', 'nombres' => $nombres, 'apellidos' => $apellidos, 'tipo_identificacion' => 'cedula', 'identificacion' => Str::random(10), 'estado' => 'activo']);
        $owner->edificios()->attach($edificio->id);
        DepartamentoPropietarioEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'departamento_id' => $departamento->id, 'propietario_id' => $owner->id, 'nombre_propietario' => trim($nombres.' '.$apellidos), 'tipo_identificacion_snapshot' => 'cedula', 'identificacion_snapshot' => $owner->identificacion, 'porcentaje' => $porcentaje, 'fecha_inicio' => '2025-01-01', 'fecha_fin' => null, 'estado' => 'activa']);

        return $owner;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function paymentData(DepartamentoEloquentModel $departamento, array $overrides = []): array
    {
        return array_merge(['departamento_id' => $departamento->id, 'fecha_pago' => '2026-02-01', 'valor_recibido' => '100.0000', 'forma_pago' => 'efectivo', 'referencia' => null, 'observacion' => null], $overrides);
    }
}
