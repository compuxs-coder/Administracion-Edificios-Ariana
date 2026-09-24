<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Contracts\DesembolsoRepositoryInterface;
use Src\Gastos\Domain\Enums\EstadoDesembolso;
use Src\Gastos\Domain\Enums\EstadoGasto;
use Src\Gastos\Infrastructure\Models\CuentaPorPagarEloquentModel;
use Src\Gastos\Infrastructure\Models\DesembolsoEloquentModel;
use Src\Gastos\Infrastructure\Models\GastoEloquentModel;
use Src\Gastos\Infrastructure\Models\ProveedorEloquentModel;
use Src\Tesoreria\Domain\Enums\EstadoConciliacionTesoreria;
use Src\Tesoreria\Domain\Enums\EstadoCuentaTesoreria;
use Src\Tesoreria\Domain\Enums\EstadoMovimientoTesoreria;
use Src\Tesoreria\Infrastructure\Models\ConciliacionTesoreriaEloquentModel;
use Src\Tesoreria\Infrastructure\Models\CuentaTesoreriaEloquentModel;
use Src\Tesoreria\Infrastructure\Models\MovimientoTesoreriaEloquentModel;
use Tests\TestCase;

final class TesoreriaWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_require_authentication_and_expose_no_destructive_operations(): void
    {
        [, $building] = $this->fixture();
        $id = (string) Str::uuid();

        $this->get(route('cuentas-tesoreria.index'))->assertRedirect(route('login'));
        $this->get(route('cuentas-tesoreria.create'))->assertRedirect(route('login'));
        $this->post(route('cuentas-tesoreria.store', $building), [])->assertRedirect(route('login'));
        $this->get(route('cuentas-tesoreria.show', [$building, $id]))->assertRedirect(route('login'));
        $this->get(route('cuentas-tesoreria.edit', [$building, $id]))->assertRedirect(route('login'));
        $this->put(route('cuentas-tesoreria.update', [$building, $id]), [])->assertRedirect(route('login'));
        $this->patch(route('cuentas-tesoreria.status', [$building, $id]), [])->assertRedirect(route('login'));
        $this->get(route('movimientos-tesoreria.index'))->assertRedirect(route('login'));
        $this->get(route('movimientos-tesoreria.create'))->assertRedirect(route('login'));
        $this->post(route('movimientos-tesoreria.store', [$building, $id]), [])->assertRedirect(route('login'));
        $this->get(route('movimientos-tesoreria.show', [$building, $id, $id]))->assertRedirect(route('login'));
        $this->patch(route('movimientos-tesoreria.cancel', [$building, $id, $id]), [])->assertRedirect(route('login'));
        $this->get(route('movimientos-tesoreria.reconcile', [$building, $id, $id]))->assertRedirect(route('login'));
        $this->post(route('conciliaciones-tesoreria.store', [$building, $id, $id]), [])->assertRedirect(route('login'));
        $this->patch(route('conciliaciones-tesoreria.reverse', [$building, $id, $id, $id]), [])->assertRedirect(route('login'));

        $this->assertFalse(Route::has('cuentas-tesoreria.destroy'));
        $this->assertFalse(Route::has('movimientos-tesoreria.update'));
        $this->assertFalse(Route::has('movimientos-tesoreria.destroy'));
        $this->assertFalse(Route::has('conciliaciones-tesoreria.destroy'));
    }

    public function test_bank_and_cash_accounts_validate_fields_mask_numbers_and_derive_balances(): void
    {
        [$user, $building] = $this->fixture();
        $bank = $this->createAccount($user, $building);
        $cash = $this->createAccount($user, $building, [
            'codigo' => ' caja-01 ',
            'nombre' => 'Caja principal',
            'tipo' => 'caja',
            'entidad_financiera' => null,
            'tipo_cuenta_bancaria' => null,
            'numero_cuenta' => null,
        ]);

        $this->assertSame('BANCO-01', $bank->codigo);
        $this->assertSame('CAJA-01', $cash->codigo);
        $this->actingAs($user)->post(route('cuentas-tesoreria.store', $building), [
            ...$this->bankAccountData(),
            'codigo' => 'BANCO-02',
            'numero_cuenta' => null,
        ])->assertSessionHasErrors('numeroCuenta');
        $this->actingAs($user)->post(route('cuentas-tesoreria.store', $building), [
            ...$this->bankAccountData(),
            'codigo' => 'CAJA-02',
            'tipo' => 'caja',
        ])->assertSessionHasErrors(['entidadFinanciera', 'tipoCuentaBancaria', 'numeroCuenta']);

        $this->createMovement($user, $building, $bank, ['naturaleza' => 'ingreso', 'monto' => '125.5000']);
        $this->createMovement($user, $building, $bank, ['naturaleza' => 'egreso', 'monto' => '25.2500']);

        $this->actingAs($user)->get(route('cuentas-tesoreria.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('CuentaTesoreria/index')
                ->has('cuentas.data', 2)
                ->where('cuentas.data.0.numeroCuenta', null)
                ->where('cuentas.data.0.numeroCuentaMascara', '****6789'));
        $this->actingAs($user)->get(route('cuentas-tesoreria.show', [$building, $bank]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('CuentaTesoreria/show')
                ->where('cuenta.numeroCuenta', null)
                ->where('cuenta.saldoRegistrado', '100.2500')
                ->where('cuenta.totalIngresos', '125.5000')
                ->where('cuenta.totalEgresos', '25.2500')
                ->where('cuenta.movimientosCount', 2));
        $this->actingAs($user)->get(route('cuentas-tesoreria.edit', [$building, $bank]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('CuentaTesoreria/edit')
                ->where('cuenta.numeroCuenta', '00123456789'));
    }

    public function test_account_updates_status_and_identity_rules_are_enforced(): void
    {
        [$user, $building] = $this->fixture();
        $account = $this->createAccount($user, $building);
        $this->createMovement($user, $building, $account);

        $this->actingAs($user)->put(route('cuentas-tesoreria.update', [$building, $account]), [
            ...$this->bankAccountData(),
            'codigo' => 'BANCO-RENOMBRADO',
            'nombre' => 'Cuenta operativa actualizada',
        ])->assertSessionHasNoErrors();
        $account->refresh();
        $this->assertSame('BANCO-RENOMBRADO', $account->codigo);
        $this->assertSame('Cuenta operativa actualizada', $account->nombre);

        $this->actingAs($user)->put(route('cuentas-tesoreria.update', [$building, $account]), [
            ...$this->bankAccountData(),
            'codigo' => $account->codigo,
            'numero_cuenta' => '9999999999',
        ])->assertSessionHasErrors('cuenta');
        $this->assertSame('00123456789', $account->fresh()->numero_cuenta);

        $this->actingAs($user)->patch(route('cuentas-tesoreria.status', [$building, $account]), ['estado' => 'inactiva'])
            ->assertSessionHasNoErrors();
        $this->assertSame(EstadoCuentaTesoreria::INACTIVA, $account->fresh()->estado);
        $this->actingAs($user)->post(route('movimientos-tesoreria.store', [$building, $account]), $this->movementData())
            ->assertSessionHasErrors('cuenta');
        $this->actingAs($user)->patch(route('cuentas-tesoreria.status', [$building, $account]), ['estado' => 'activa'])
            ->assertSessionHasNoErrors();
        $this->assertSame(EstadoCuentaTesoreria::ACTIVA, $account->fresh()->estado);
    }

    public function test_movements_are_manual_immutable_traceable_and_cancellation_updates_derived_balance(): void
    {
        [$user, $building] = $this->fixture();
        $account = $this->createAccount($user, $building);
        $income = $this->createMovement($user, $building, $account, ['naturaleza' => 'ingreso', 'monto' => '90.0000']);
        $expense = $this->createMovement($user, $building, $account, ['naturaleza' => 'egreso', 'monto' => '30.0000']);

        $this->actingAs($user)->get(route('movimientos-tesoreria.index', ['estado_conciliacion' => 'pendiente']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('MovimientoTesoreria/index')
                ->has('movimientos.data', 1)
                ->where('movimientos.data.0.id', $expense->id)
                ->where('movimientos.data.0.estadoConciliacion', 'pendiente'));
        $this->actingAs($user)->get(route('movimientos-tesoreria.show', [$building, $account, $income]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('MovimientoTesoreria/show')
                ->where('movimiento.estadoConciliacion', 'no_aplica')
                ->has('movimiento.historialConciliaciones', 0));

        $this->actingAs($user)->patch(route('movimientos-tesoreria.cancel', [$building, $account, $expense]), [
            'motivo' => 'Registro bancario duplicado',
        ])->assertSessionHasNoErrors();
        $expense->refresh();
        $this->assertSame(EstadoMovimientoTesoreria::ANULADO, $expense->estado);
        $this->assertSame($user->id, $expense->anulado_por);
        $this->assertSame('Registro bancario duplicado', $expense->motivo_anulacion);
        $this->actingAs($user)->patch(route('movimientos-tesoreria.cancel', [$building, $account, $expense]), [
            'motivo' => 'Segundo intento',
        ])->assertSessionHasErrors('estado');
        $this->actingAs($user)->get(route('cuentas-tesoreria.show', [$building, $account]))
            ->assertInertia(fn (Assert $page) => $page->where('cuenta.saldoRegistrado', '90.0000'));
    }

    public function test_bank_expense_reconciles_exactly_and_reversal_unlocks_both_documents(): void
    {
        [$user, $building] = $this->fixture();
        $account = $this->createAccount($user, $building);
        $movement = $this->createMovement($user, $building, $account, ['monto' => '60.0000']);
        $payment = $this->createDisbursement($user, $building, '60.0000', 'transferencia');

        $this->actingAs($user)->get(route('movimientos-tesoreria.reconcile', [$building, $account, $movement]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('MovimientoTesoreria/reconcile')
                ->where('movimiento.id', $movement->id)
                ->has('desembolsos', 1)
                ->where('desembolsos.0.id', $payment->id));
        $this->actingAs($user)->post(route('conciliaciones-tesoreria.store', [$building, $account, $movement]), [
            'desembolso_id' => $payment->id,
            'nota' => 'Verificado contra referencia bancaria',
        ])->assertSessionHasNoErrors();
        $reconciliation = ConciliacionTesoreriaEloquentModel::query()->sole();
        $this->assertSame(EstadoConciliacionTesoreria::VIGENTE, $reconciliation->estado);

        $this->actingAs($user)->patch(route('movimientos-tesoreria.cancel', [$building, $account, $movement]), [
            'motivo' => 'Intento bloqueado',
        ])->assertSessionHasErrors('conciliacion');
        $this->actingAs($user)->patch(route('desembolsos.cancel', [$building, $payment]), [
            'motivo' => 'Intento bloqueado',
        ])->assertSessionHasErrors('conciliacion');
        $this->assertSame(EstadoMovimientoTesoreria::REGISTRADO, $movement->fresh()->estado);
        $this->assertSame(EstadoDesembolso::REGISTRADO, $payment->fresh()->estado);

        $this->actingAs($user)->patch(route('conciliaciones-tesoreria.reverse', [$building, $account, $movement, $reconciliation]), [
            'motivo' => 'La referencia bancaria no correspondía',
        ])->assertSessionHasNoErrors();
        $reconciliation->refresh();
        $this->assertSame(EstadoConciliacionTesoreria::REVERTIDA, $reconciliation->estado);
        $this->assertSame($user->id, $reconciliation->revertido_por);
        $this->actingAs($user)->patch(route('desembolsos.cancel', [$building, $payment]), [
            'motivo' => 'Documento corregido después de revertir',
        ])->assertSessionHasNoErrors();
        $this->actingAs($user)->patch(route('movimientos-tesoreria.cancel', [$building, $account, $movement]), [
            'motivo' => 'Movimiento bancario descartado',
        ])->assertSessionHasNoErrors();
    }

    public function test_reconciliation_rejects_non_exact_incoming_and_incompatible_documents(): void
    {
        [$user, $building] = $this->fixture();
        $bank = $this->createAccount($user, $building);
        $cash = $this->createAccount($user, $building, [
            'codigo' => 'CAJA-01',
            'nombre' => 'Caja principal',
            'tipo' => 'caja',
            'entidad_financiera' => null,
            'tipo_cuenta_bancaria' => null,
            'numero_cuenta' => null,
        ]);
        $bankExpense = $this->createMovement($user, $building, $bank, ['monto' => '50.0000']);
        $cashExpense = $this->createMovement($user, $building, $cash, ['monto' => '30.0000']);
        $income = $this->createMovement($user, $building, $bank, ['naturaleza' => 'ingreso', 'monto' => '20.0000']);
        $wrongAmount = $this->createDisbursement($user, $building, '40.0000', 'transferencia');
        $cashPayment = $this->createDisbursement($user, $building, '50.0000', 'efectivo');
        $compatibleCash = $this->createDisbursement($user, $building, '30.0000', 'efectivo');

        $this->actingAs($user)->post(route('conciliaciones-tesoreria.store', [$building, $bank, $bankExpense]), [
            'desembolso_id' => $wrongAmount->id,
        ])->assertSessionHasErrors('desembolsoId');
        $this->actingAs($user)->post(route('conciliaciones-tesoreria.store', [$building, $bank, $bankExpense]), [
            'desembolso_id' => $cashPayment->id,
        ])->assertSessionHasErrors('desembolsoId');
        $this->actingAs($user)->post(route('conciliaciones-tesoreria.store', [$building, $bank, $income]), [
            'desembolso_id' => $wrongAmount->id,
        ])->assertSessionHasErrors('movimiento');
        $this->assertDatabaseCount('conciliaciones_tesoreria', 0);

        $this->actingAs($user)->post(route('conciliaciones-tesoreria.store', [$building, $cash, $cashExpense]), [
            'desembolso_id' => $compatibleCash->id,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('conciliaciones_tesoreria', 1);
    }

    public function test_one_to_one_active_reconciliation_and_history_are_preserved(): void
    {
        [$user, $building] = $this->fixture();
        $account = $this->createAccount($user, $building);
        $firstMovement = $this->createMovement($user, $building, $account, ['monto' => '25.0000']);
        $secondMovement = $this->createMovement($user, $building, $account, ['monto' => '25.0000', 'referencia' => 'MOV-002']);
        $firstPayment = $this->createDisbursement($user, $building, '25.0000', 'transferencia');
        $secondPayment = $this->createDisbursement($user, $building, '25.0000', 'transferencia');

        $this->reconcile($user, $building, $account, $firstMovement, $firstPayment);
        $firstReconciliation = ConciliacionTesoreriaEloquentModel::query()->sole();
        $this->actingAs($user)->post(route('conciliaciones-tesoreria.store', [$building, $account, $firstMovement]), [
            'desembolso_id' => $secondPayment->id,
        ])->assertSessionHasErrors('conciliacion');
        $this->actingAs($user)->post(route('conciliaciones-tesoreria.store', [$building, $account, $secondMovement]), [
            'desembolso_id' => $firstPayment->id,
        ])->assertSessionHasErrors('desembolsoId');

        $this->actingAs($user)->patch(route('conciliaciones-tesoreria.reverse', [$building, $account, $firstMovement, $firstReconciliation]), [
            'motivo' => 'Reclasificación del movimiento',
        ])->assertSessionHasNoErrors();
        $this->reconcile($user, $building, $account, $firstMovement, $secondPayment);

        $this->assertDatabaseCount('conciliaciones_tesoreria', 2);
        $this->assertSame(1, ConciliacionTesoreriaEloquentModel::query()->where('estado', 'vigente')->count());
        $this->assertSame(1, ConciliacionTesoreriaEloquentModel::query()->where('estado', 'revertida')->count());
        $this->actingAs($user)->get(route('movimientos-tesoreria.show', [$building, $account, $firstMovement]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('movimiento.estadoConciliacion', 'conciliado')
                ->has('movimiento.historialConciliaciones', 2));
    }

    public function test_treasury_data_and_nested_resources_are_strictly_isolated_by_building(): void
    {
        [$user, $firstBuilding] = $this->fixture();
        $secondBuilding = $this->buildingFor($user);
        $firstAccount = $this->createAccount($user, $firstBuilding);
        $secondAccount = $this->createAccount($user, $secondBuilding, [
            ...$this->bankAccountData(),
            'numero_cuenta' => '2222222222',
        ]);
        $secondMovement = $this->createMovement($user, $secondBuilding, $secondAccount);

        $this->actingAs($user)->get(route('cuentas-tesoreria.show', [$firstBuilding, $secondAccount]))->assertNotFound();
        $this->actingAs($user)->get(route('movimientos-tesoreria.show', [$firstBuilding, $firstAccount, $secondMovement]))->assertNotFound();
        $this->actingAs($user)->post(route('movimientos-tesoreria.store', [$firstBuilding, $secondAccount]), $this->movementData())
            ->assertNotFound();
        $this->assertDatabaseCount('movimientos_tesoreria', 1);

        $this->assertDatabaseRejects(function () use ($firstBuilding, $secondAccount, $user): void {
            DB::table('movimientos_tesoreria')->insert([
                'id' => (string) Str::uuid(),
                'edificio_id' => $firstBuilding->id,
                'cuenta_id' => $secondAccount->id,
                'fecha_movimiento' => now()->toDateString(),
                'naturaleza' => 'egreso',
                'monto' => '10.0000',
                'descripcion' => 'Cruce inválido',
                'estado' => 'registrado',
                'registrado_por' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_role_matrix_separates_read_account_movement_and_reconciliation_capabilities(): void
    {
        [$administrator, $building] = $this->fixture();
        $financeManager = UserEloquentModel::factory()->create();
        $viewer = UserEloquentModel::factory()->create();
        $propertyManager = UserEloquentModel::factory()->create();
        $this->addMember($building, $financeManager, RolEdificio::GESTOR_FINANZAS, $administrator);
        $this->addMember($building, $viewer, RolEdificio::CONSULTA, $administrator);
        $this->addMember($building, $propertyManager, RolEdificio::GESTOR_PROPIEDAD, $administrator);
        $account = $this->createAccount($administrator, $building);
        $movement = $this->createMovement($administrator, $building, $account);

        $this->actingAs($viewer)->get(route('cuentas-tesoreria.index'))->assertOk();
        $this->actingAs($viewer)->get(route('cuentas-tesoreria.show', [$building, $account]))->assertOk();
        $this->actingAs($viewer)->get(route('movimientos-tesoreria.index'))->assertOk();
        $this->actingAs($viewer)->get(route('movimientos-tesoreria.show', [$building, $account, $movement]))->assertOk();
        $this->actingAs($viewer)->get(route('cuentas-tesoreria.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('movimientos-tesoreria.store', [$building, $account]), $this->movementData())->assertForbidden();
        $this->actingAs($viewer)->patch(route('movimientos-tesoreria.cancel', [$building, $account, $movement]), ['motivo' => 'No autorizado'])->assertForbidden();
        $this->actingAs($viewer)->get(route('movimientos-tesoreria.reconcile', [$building, $account, $movement]))->assertForbidden();
        $this->actingAs($propertyManager)->get(route('cuentas-tesoreria.index'))->assertForbidden();

        $financeAccount = $this->createAccount($financeManager, $building, [
            ...$this->bankAccountData(),
            'codigo' => 'BANCO-02',
            'numero_cuenta' => '9876543210',
        ]);
        $this->createMovement($financeManager, $building, $financeAccount);
        $this->assertDatabaseCount('cuentas_tesoreria', 2);
        $this->assertDatabaseCount('movimientos_tesoreria', 2);
    }

    public function test_database_guards_preserve_treasury_history_and_cross_lifecycle_integrity(): void
    {
        [$user, $building] = $this->fixture();
        $account = $this->createAccount($user, $building);
        $movement = $this->createMovement($user, $building, $account, ['monto' => '35.0000']);
        $payment = $this->createDisbursement($user, $building, '35.0000', 'transferencia');
        $this->reconcile($user, $building, $account, $movement, $payment);
        $reconciliation = ConciliacionTesoreriaEloquentModel::query()->sole();

        $this->assertDatabaseRejects(static fn () => DB::table('cuentas_tesoreria')->where('id', $account->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('movimientos_tesoreria')->where('id', $movement->id)->update(['monto' => '36.0000']));
        $this->assertDatabaseRejects(static fn () => DB::table('movimientos_tesoreria')->where('id', $movement->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('conciliaciones_tesoreria')->where('id', $reconciliation->id)->update(['nota' => 'Mutación inválida']));
        $this->assertDatabaseRejects(static fn () => DB::table('conciliaciones_tesoreria')->where('id', $reconciliation->id)->delete());

        $this->assertDatabaseRejects(function () use ($building, $user): void {
            DB::table('cuentas_tesoreria')->insert([
                'id' => (string) Str::uuid(),
                'edificio_id' => $building->id,
                'codigo' => 'CAJA-INVALIDA',
                'nombre' => 'Caja inválida',
                'tipo' => 'caja',
                'entidad_financiera' => 'No permitida',
                'estado' => 'activa',
                'registrado_por' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel} */
    private function fixture(): array
    {
        $user = UserEloquentModel::factory()->create();

        return [$user, $this->buildingFor($user)];
    }

    private function buildingFor(UserEloquentModel $user): EdificioEloquentModel
    {
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

        return EdificioEloquentModel::query()->findOrFail($created->id());
    }

    /** @param array<string, mixed> $overrides */
    private function createAccount(
        UserEloquentModel $user,
        EdificioEloquentModel $building,
        array $overrides = [],
    ): CuentaTesoreriaEloquentModel {
        $data = array_merge($this->bankAccountData(), $overrides);
        $this->actingAs($user)->post(route('cuentas-tesoreria.store', $building), $data)
            ->assertSessionHasNoErrors();

        return CuentaTesoreriaEloquentModel::query()
            ->where('edificio_id', $building->id)
            ->where('codigo', mb_strtoupper(trim((string) $data['codigo'])))
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function bankAccountData(): array
    {
        return [
            'codigo' => ' banco-01 ',
            'nombre' => 'Cuenta operativa',
            'tipo' => 'bancaria',
            'entidad_financiera' => 'Banco Ariana',
            'tipo_cuenta_bancaria' => 'corriente',
            'numero_cuenta' => '00123456789',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createMovement(
        UserEloquentModel $user,
        EdificioEloquentModel $building,
        CuentaTesoreriaEloquentModel $account,
        array $overrides = [],
    ): MovimientoTesoreriaEloquentModel {
        $data = array_merge($this->movementData(), $overrides);
        $this->actingAs($user)->post(route('movimientos-tesoreria.store', [$building, $account]), $data)
            ->assertSessionHasNoErrors();

        return MovimientoTesoreriaEloquentModel::query()
            ->where('cuenta_id', $account->id)
            ->where('referencia', $data['referencia'])
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function movementData(): array
    {
        return [
            'fecha_movimiento' => now()->toDateString(),
            'naturaleza' => 'egreso',
            'monto' => '20.0000',
            'referencia' => 'MOV-'.Str::upper(Str::random(10)),
            'descripcion' => 'Movimiento registrado manualmente',
        ];
    }

    private function createDisbursement(
        UserEloquentModel $user,
        EdificioEloquentModel $building,
        string $amount,
        string $form,
    ): DesembolsoEloquentModel {
        $supplier = $this->createSupplier($user, $building);
        $this->createCreditExpense($user, $building, $supplier, $amount);
        /** @var DesembolsoRepositoryInterface $repository */
        $repository = $this->app->make(DesembolsoRepositoryInterface::class);
        $preview = $repository->preview($user->id, $building->id, $supplier->id, $amount);
        $this->actingAs($user)->post(route('desembolsos.store', $building), [
            'proveedor_id' => $supplier->id,
            'fecha_desembolso' => now()->toDateString(),
            'monto' => $amount,
            'forma_pago' => $form,
            'referencia' => $form === 'efectivo' ? null : Str::upper(Str::random(12)),
            'observacion' => 'Desembolso para conciliación',
            'aplicacion_fingerprint' => $preview['aplicacionFingerprint'],
        ])->assertSessionHasNoErrors();

        return DesembolsoEloquentModel::query()
            ->where('edificio_id', $building->id)
            ->where('proveedor_id', $supplier->id)
            ->latest('created_at')
            ->firstOrFail();
    }

    private function createSupplier(UserEloquentModel $user, EdificioEloquentModel $building): ProveedorEloquentModel
    {
        $identification = '179'.random_int(1000000000, 9999999999);
        $this->actingAs($user)->post(route('proveedores.store', $building), [
            'tipo_persona' => 'persona_juridica',
            'nombres' => null,
            'apellidos' => null,
            'razon_social' => 'Proveedor '.Str::upper(Str::random(6)),
            'tipo_identificacion' => 'ruc',
            'identificacion' => $identification,
            'telefono' => '022111111',
            'celular' => null,
            'correo' => Str::lower(Str::random(8)).'@example.test',
            'direccion' => 'Av. Proveedores 100',
            'nombre_comercial' => 'Proveedor de prueba',
            'contacto' => 'Contacto Tesorería',
            'telefono_comercial' => '0999999999',
            'correo_comercial' => null,
            'direccion_comercial' => null,
            'dias_credito' => 30,
            'observaciones' => null,
        ])->assertSessionHasNoErrors();

        return ProveedorEloquentModel::query()
            ->whereHas('tercero', static fn ($query) => $query->where('identificacion', $identification))
            ->firstOrFail();
    }

    private function createCreditExpense(
        UserEloquentModel $user,
        EdificioEloquentModel $building,
        ProveedorEloquentModel $supplier,
        string $amount,
    ): CuentaPorPagarEloquentModel {
        $this->actingAs($user)->post(route('gastos.store', $building), [
            'proveedor_id' => $supplier->id,
            'contrato_id' => null,
            'fecha_gasto' => now()->toDateString(),
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
            'concepto' => 'Servicio para conciliación',
            'referencia' => Str::upper(Str::random(10)),
            'monto' => $amount,
            'tipo_pago' => 'credito',
            'observaciones' => null,
        ])->assertSessionHasNoErrors();
        $expense = GastoEloquentModel::query()
            ->where('edificio_id', $building->id)
            ->where('proveedor_id', $supplier->id)
            ->where('estado', EstadoGasto::BORRADOR->value)
            ->sole();
        $this->actingAs($user)->patch(route('gastos.register', [$building, $expense]))
            ->assertSessionHasNoErrors();

        return CuentaPorPagarEloquentModel::query()->where('gasto_id', $expense->id)->firstOrFail();
    }

    private function reconcile(
        UserEloquentModel $user,
        EdificioEloquentModel $building,
        CuentaTesoreriaEloquentModel $account,
        MovimientoTesoreriaEloquentModel $movement,
        DesembolsoEloquentModel $payment,
    ): void {
        $this->actingAs($user)->post(route('conciliaciones-tesoreria.store', [$building, $account, $movement]), [
            'desembolso_id' => $payment->id,
            'nota' => 'Conciliación de prueba',
        ])->assertSessionHasNoErrors();
    }

    private function addMember(
        EdificioEloquentModel $building,
        UserEloquentModel $member,
        RolEdificio $role,
        UserEloquentModel $administrator,
    ): void {
        $building->usuarios()->attach($member->id, ['creado_por_user_id' => $administrator->id]);
        DB::table('edificio_usuario_roles')->insert([
            'edificio_id' => $building->id,
            'user_id' => $member->id,
            'rol_codigo' => $role->value,
            'asignado_por_user_id' => $administrator->id,
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
