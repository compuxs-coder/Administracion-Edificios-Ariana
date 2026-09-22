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
use Src\Gastos\Domain\Enums\EstadoPagoGasto;
use Src\Gastos\Infrastructure\Models\AplicacionDesembolsoEloquentModel;
use Src\Gastos\Infrastructure\Models\CuentaPorPagarEloquentModel;
use Src\Gastos\Infrastructure\Models\DesembolsoEloquentModel;
use Src\Gastos\Infrastructure\Models\GastoEloquentModel;
use Src\Gastos\Infrastructure\Models\ProveedorEloquentModel;
use Tests\TestCase;

final class DesembolsosWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_require_authentication_and_expose_no_destructive_operations(): void
    {
        [, $building] = $this->fixture();
        $resourceId = (string) Str::uuid();

        $this->get(route('desembolsos.index'))->assertRedirect(route('login'));
        $this->get(route('desembolsos.create'))->assertRedirect(route('login'));
        $this->post(route('desembolsos.store', $building), [])->assertRedirect(route('login'));
        $this->get(route('desembolsos.show', [$building, $resourceId]))->assertRedirect(route('login'));
        $this->patch(route('desembolsos.cancel', [$building, $resourceId]), [])->assertRedirect(route('login'));

        $this->assertFalse(Route::has('desembolsos.update'));
        $this->assertFalse(Route::has('desembolsos.destroy'));
    }

    public function test_disbursement_applies_by_due_date_across_accounts_and_derives_settlement_states(): void
    {
        [$user, $building] = $this->fixture();
        $supplier = $this->createSupplier($user, $building);
        $first = $this->createCreditExpense($user, $building, $supplier, '100.0000', '2026-10-01', 'Primera factura');
        $second = $this->createCreditExpense($user, $building, $supplier, '80.0000', '2026-11-01', 'Segunda factura');

        $this->actingAs($user)->get(route('desembolsos.create', [
            'edificio_id' => $building->id,
            'proveedor_id' => $supplier->id,
            'fecha_desembolso' => '2026-09-22',
            'monto' => '150.0000',
        ]))->assertInertia(fn (Assert $page) => $page
            ->component('Desembolso/create')
            ->where('preview.saldoPendiente', '180.0000')
            ->where('preview.montoAplicado', '150.0000')
            ->has('preview.cuentas', 2)
            ->where('preview.cuentas.0.cuentaId', $first->id)
            ->where('preview.cuentas.0.montoAplicado', '100.0000')
            ->where('preview.cuentas.1.cuentaId', $second->id)
            ->where('preview.cuentas.1.montoAplicado', '50.0000'));

        $this->actingAs($user)->post(route('desembolsos.store', $building), $this->validDisbursementData($user, $building, $supplier, '150.0000'))
            ->assertSessionHasNoErrors();
        $payment = DesembolsoEloquentModel::query()->sole();
        $first->refresh();
        $second->refresh();

        $this->assertSame('DES-2026-000001', $payment->numero);
        $this->assertSame(EstadoDesembolso::REGISTRADO, $payment->estado);
        $this->assertSame('0.0000', $first->saldo);
        $this->assertSame('30.0000', $second->saldo);
        $this->assertDatabaseCount('aplicaciones_desembolso', 2);
        $this->assertSame('100.0000', AplicacionDesembolsoEloquentModel::query()->where('cuenta_por_pagar_id', $first->id)->sole()->monto_aplicado);
        $this->assertSame('50.0000', AplicacionDesembolsoEloquentModel::query()->where('cuenta_por_pagar_id', $second->id)->sole()->monto_aplicado);
        $this->assertSame(EstadoPagoGasto::PAGADO, $first->gasto->fresh()->estado_pago);
        $this->assertSame(EstadoPagoGasto::PENDIENTE, $second->gasto->fresh()->estado_pago);

        $this->actingAs($user)->get(route('cuentas-por-pagar.index', ['estado' => 'pagada']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('cuentasPorPagar.data', 1)
                ->where('cuentasPorPagar.data.0.id', $first->id)
                ->where('cuentasPorPagar.data.0.estado', 'pagada'));
        $this->actingAs($user)->get(route('cuentas-por-pagar.index', ['estado' => 'parcial']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('cuentasPorPagar.data', 1)
                ->where('cuentasPorPagar.data.0.id', $second->id)
                ->where('cuentasPorPagar.data.0.estado', 'parcial'));
        $this->actingAs($user)->get(route('desembolsos.show', [$building, $payment]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Desembolso/show')
                ->where('desembolso.numero', 'DES-2026-000001')
                ->where('desembolso.cantidadCuentas', 2)
                ->has('desembolso.aplicaciones', 2)
                ->where('desembolso.aplicaciones.0.cuentaId', $first->id)
                ->where('desembolso.aplicaciones.1.cuentaId', $second->id));
    }

    public function test_overpayment_and_missing_reference_are_rejected_without_financial_effects(): void
    {
        [$user, $building] = $this->fixture();
        $supplier = $this->createSupplier($user, $building);
        $account = $this->createCreditExpense($user, $building, $supplier, '75.0000');

        $this->actingAs($user)->post(route('desembolsos.store', $building), $this->disbursementData($supplier, '80.0000'))
            ->assertSessionHasErrors('monto');
        $this->actingAs($user)->post(route('desembolsos.store', $building), [
            ...$this->validDisbursementData($user, $building, $supplier, '50.0000'),
            'referencia' => null,
        ])->assertSessionHasErrors('referencia');

        $this->assertDatabaseCount('desembolsos', 0);
        $this->assertDatabaseCount('aplicaciones_desembolso', 0);
        $this->assertDatabaseCount('consecutivos_desembolso', 0);
        $this->assertSame('75.0000', $account->fresh()->saldo);
    }

    public function test_cancellation_restores_accounts_once_and_expense_can_only_then_be_cancelled(): void
    {
        [$user, $building] = $this->fixture();
        $supplier = $this->createSupplier($user, $building);
        $account = $this->createCreditExpense($user, $building, $supplier, '125.0000');
        $this->actingAs($user)->post(route('desembolsos.store', $building), $this->validDisbursementData($user, $building, $supplier, '50.0000'))
            ->assertSessionHasNoErrors();
        $payment = DesembolsoEloquentModel::query()->sole();
        $expense = $account->gasto;

        $this->actingAs($user)->patch(route('gastos.cancel', [$building, $expense]), ['motivo' => 'Intento prematuro'])
            ->assertSessionHasErrors('estado');
        $this->actingAs($user)->patch(route('desembolsos.cancel', [$building, $payment]), ['motivo' => 'Transferencia rechazada'])
            ->assertSessionHasNoErrors();
        $payment->refresh();
        $account->refresh();
        $expense->refresh();
        $this->assertSame(EstadoDesembolso::ANULADO, $payment->estado);
        $this->assertSame('125.0000', $account->saldo);
        $this->assertSame(EstadoPagoGasto::PENDIENTE, $expense->estado_pago);
        $this->assertNull($expense->pagado_at);

        $this->actingAs($user)->patch(route('desembolsos.cancel', [$building, $payment]), ['motivo' => 'Segundo intento'])
            ->assertSessionHasErrors('estado');
        $this->actingAs($user)->patch(route('gastos.cancel', [$building, $expense]), ['motivo' => 'Documento inválido'])
            ->assertSessionHasNoErrors();
        $this->assertSame(EstadoGasto::ANULADO, $expense->fresh()->estado);
        $this->assertSame('125.0000', $account->fresh()->saldo);
    }

    public function test_cancelling_one_of_multiple_disbursements_preserves_the_other_active_application(): void
    {
        [$user, $building] = $this->fixture();
        $supplier = $this->createSupplier($user, $building);
        $account = $this->createCreditExpense($user, $building, $supplier, '100.0000');
        $this->actingAs($user)->post(
            route('desembolsos.store', $building),
            $this->validDisbursementData($user, $building, $supplier, '30.0000'),
        )->assertSessionHasNoErrors();
        $first = DesembolsoEloquentModel::query()->sole();
        $this->actingAs($user)->post(
            route('desembolsos.store', $building),
            $this->validDisbursementData($user, $building, $supplier, '40.0000'),
        )->assertSessionHasNoErrors();
        $second = DesembolsoEloquentModel::query()->where('id', '!=', $first->id)->sole();
        $this->assertSame('30.0000', $account->fresh()->saldo);

        $this->actingAs($user)->patch(route('desembolsos.cancel', [$building, $first]), ['motivo' => 'Reversión parcial'])
            ->assertSessionHasNoErrors();
        $this->assertSame('60.0000', $account->fresh()->saldo);
        $this->assertSame(EstadoDesembolso::REGISTRADO, $second->fresh()->estado);

        $this->actingAs($user)->patch(route('desembolsos.cancel', [$building, $second]), ['motivo' => 'Reversión restante'])
            ->assertSessionHasNoErrors();
        $this->assertSame('100.0000', $account->fresh()->saldo);
    }

    public function test_disbursements_are_strictly_isolated_by_building_and_supplier(): void
    {
        [$firstUser, $firstBuilding] = $this->fixture();
        [$secondUser, $secondBuilding] = $this->fixture();
        $firstSupplier = $this->createSupplier($firstUser, $firstBuilding, ['identificacion' => '1790000000001']);
        $secondSupplier = $this->createSupplier($secondUser, $secondBuilding, ['identificacion' => '1790000000002']);
        $this->createCreditExpense($firstUser, $firstBuilding, $firstSupplier, '90.0000');
        $this->createCreditExpense($secondUser, $secondBuilding, $secondSupplier, '110.0000');
        $this->actingAs($secondUser)->post(route('desembolsos.store', $secondBuilding), $this->validDisbursementData($secondUser, $secondBuilding, $secondSupplier, '30.0000'))
            ->assertSessionHasNoErrors();
        $payment = DesembolsoEloquentModel::query()->sole();

        $this->actingAs($firstUser)->get(route('desembolsos.index'))
            ->assertInertia(fn (Assert $page) => $page->has('desembolsos.data', 0));
        $this->actingAs($firstUser)->get(route('desembolsos.show', [$secondBuilding, $payment]))
            ->assertNotFound();
        $this->actingAs($firstUser)->post(route('desembolsos.store', $firstBuilding), $this->disbursementData($secondSupplier, '10.0000'))
            ->assertSessionHasErrors('proveedorId');
        $this->assertDatabaseCount('desembolsos', 1);
        $this->assertSame('90.0000', CuentaPorPagarEloquentModel::query()->where('edificio_id', $firstBuilding->id)->sole()->saldo);

        $this->addMember($secondBuilding, $firstUser, RolEdificio::CONSULTA, $secondUser);
        $this->actingAs($firstUser)->get(route('desembolsos.show', [$secondBuilding, $payment]))->assertOk();
        $this->actingAs($firstUser)->get(route('desembolsos.show', [$firstBuilding, $payment]))->assertNotFound();
        $this->actingAs($firstUser)->patch(route('desembolsos.cancel', [$firstBuilding, $payment]), ['motivo' => 'Cruce inválido'])
            ->assertNotFound();
    }

    public function test_role_matrix_separates_read_register_and_cancel_capabilities(): void
    {
        [$administrator, $building] = $this->fixture();
        $financeManager = UserEloquentModel::factory()->create();
        $viewer = UserEloquentModel::factory()->create();
        $propertyManager = UserEloquentModel::factory()->create();
        $this->addMember($building, $financeManager, RolEdificio::GESTOR_FINANZAS, $administrator);
        $this->addMember($building, $viewer, RolEdificio::CONSULTA, $administrator);
        $this->addMember($building, $propertyManager, RolEdificio::GESTOR_PROPIEDAD, $administrator);
        $supplier = $this->createSupplier($administrator, $building);
        $this->createCreditExpense($administrator, $building, $supplier, '100.0000');
        $this->actingAs($administrator)->post(route('desembolsos.store', $building), $this->validDisbursementData($administrator, $building, $supplier, '25.0000'))
            ->assertSessionHasNoErrors();
        $payment = DesembolsoEloquentModel::query()->sole();

        $this->actingAs($viewer)->get(route('desembolsos.index'))->assertOk();
        $this->actingAs($viewer)->get(route('desembolsos.show', [$building, $payment]))->assertOk();
        $this->actingAs($viewer)->get(route('desembolsos.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('desembolsos.store', $building), $this->disbursementData($supplier, '10.0000'))->assertForbidden();
        $this->actingAs($viewer)->patch(route('desembolsos.cancel', [$building, $payment]), ['motivo' => 'No autorizado'])->assertForbidden();
        $this->actingAs($propertyManager)->get(route('desembolsos.index'))->assertForbidden();
        $this->actingAs($financeManager)->post(
            route('desembolsos.store', $building),
            $this->validDisbursementData($financeManager, $building, $supplier, '10.0000'),
        )->assertSessionHasNoErrors();
        $this->assertDatabaseCount('desembolsos', 2);
        $this->actingAs($financeManager)->patch(route('desembolsos.cancel', [$building, $payment]), ['motivo' => 'Corrección autorizada'])
            ->assertSessionHasNoErrors();
    }

    public function test_database_guards_preserve_disbursement_and_application_history(): void
    {
        [$user, $building] = $this->fixture();
        $supplier = $this->createSupplier($user, $building);
        $this->createCreditExpense($user, $building, $supplier, '100.0000');
        $this->actingAs($user)->post(route('desembolsos.store', $building), $this->validDisbursementData($user, $building, $supplier, '40.0000'))
            ->assertSessionHasNoErrors();
        $payment = DesembolsoEloquentModel::query()->sole();
        $application = AplicacionDesembolsoEloquentModel::query()->sole();

        $this->assertDatabaseRejects(static fn () => DB::table('desembolsos')->where('id', $payment->id)->update(['monto' => '41.0000']));
        $this->assertDatabaseRejects(static fn () => DB::table('desembolsos')->where('id', $payment->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('aplicaciones_desembolso')->where('id', $application->id)->update(['monto_aplicado' => '39.0000']));
        $this->assertDatabaseRejects(static fn () => DB::table('aplicaciones_desembolso')->where('id', $application->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('cuentas_por_pagar')->where('id', $application->cuenta_por_pagar_id)->update(['saldo' => '-1.0000']));

        $incompleteId = (string) Str::uuid();
        $now = now();
        $this->assertDatabaseRejects(static function () use ($incompleteId, $building, $supplier, $user, $now): void {
            DB::table('desembolsos')->insert([
                'id' => $incompleteId,
                'edificio_id' => $building->id,
                'proveedor_id' => $supplier->id,
                'numero' => 'DES-2026-999999',
                'fecha_desembolso' => '2026-09-22',
                'monto' => '10.0000',
                'forma_pago' => 'efectivo',
                'proveedor_nombre_snapshot' => 'Proveedor incompleto',
                'proveedor_identificacion_snapshot' => '1791234567001',
                'estado' => 'preparando',
                'registrado_por' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('desembolsos')->where('id', $incompleteId)->update(['estado' => 'registrado']);
        });
    }

    public function test_failed_application_rolls_back_disbursement_balances_and_number(): void
    {
        [$user, $building] = $this->fixture();
        $supplier = $this->createSupplier($user, $building);
        $account = $this->createCreditExpense($user, $building, $supplier, '100.0000');
        $dispatcher = AplicacionDesembolsoEloquentModel::getEventDispatcher();
        AplicacionDesembolsoEloquentModel::creating(static function (): void {
            throw new \RuntimeException('Fallo simulado al aplicar el desembolso.');
        });

        try {
            /** @var DesembolsoRepositoryInterface $repository */
            $repository = $this->app->make(DesembolsoRepositoryInterface::class);
            $repository->create($user->id, $building->id, $this->validDisbursementData($user, $building, $supplier, '60.0000'));
            $this->fail('El desembolso debía revertirse por completo.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fallo simulado al aplicar el desembolso.', $exception->getMessage());
        } finally {
            AplicacionDesembolsoEloquentModel::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseCount('desembolsos', 0);
        $this->assertDatabaseCount('aplicaciones_desembolso', 0);
        $this->assertDatabaseCount('consecutivos_desembolso', 0);
        $this->assertSame('100.0000', $account->fresh()->saldo);
        $this->assertSame(EstadoPagoGasto::PENDIENTE, $account->gasto->fresh()->estado_pago);
    }

    public function test_stale_preview_is_rejected_when_the_automatic_application_plan_changes(): void
    {
        [$user, $building] = $this->fixture();
        $supplier = $this->createSupplier($user, $building);
        $original = $this->createCreditExpense($user, $building, $supplier, '100.0000', '2026-12-31');
        $data = $this->validDisbursementData($user, $building, $supplier, '50.0000');
        $newerPlanAccount = $this->createCreditExpense($user, $building, $supplier, '25.0000', '2026-10-01');

        $this->actingAs($user)->post(route('desembolsos.store', $building), $data)
            ->assertSessionHasErrors('aplicacionFingerprint');

        $this->assertDatabaseCount('desembolsos', 0);
        $this->assertSame('100.0000', $original->fresh()->saldo);
        $this->assertSame('25.0000', $newerPlanAccount->fresh()->saldo);
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

    private function createSupplier(UserEloquentModel $user, EdificioEloquentModel $building, array $overrides = []): ProveedorEloquentModel
    {
        $data = array_merge([
            'tipo_persona' => 'persona_juridica',
            'nombres' => null,
            'apellidos' => null,
            'razon_social' => 'Servicios de Mantenimiento SA',
            'tipo_identificacion' => 'ruc',
            'identificacion' => '1791234567001',
            'telefono' => '022111111',
            'celular' => null,
            'correo' => 'proveedor@example.test',
            'direccion' => 'Av. Proveedores 100',
            'nombre_comercial' => 'Mantenimiento Ariana',
            'contacto' => 'Ana Técnica',
            'telefono_comercial' => '0999999999',
            'correo_comercial' => 'ventas@example.test',
            'direccion_comercial' => 'Av. Comercial 123',
            'dias_credito' => 30,
            'observaciones' => null,
        ], $overrides);
        $this->actingAs($user)->post(route('proveedores.store', $building), $data)->assertSessionHasNoErrors();

        return ProveedorEloquentModel::query()
            ->whereHas('tercero', static fn ($query) => $query
                ->where('tipo_identificacion', $data['tipo_identificacion'])
                ->where('identificacion', $data['identificacion']))
            ->firstOrFail();
    }

    private function createCreditExpense(
        UserEloquentModel $user,
        EdificioEloquentModel $building,
        ProveedorEloquentModel $supplier,
        string $amount,
        string $dueDate = '2026-12-31',
        string $concept = 'Servicio mensual',
    ): CuentaPorPagarEloquentModel {
        $this->actingAs($user)->post(route('gastos.store', $building), [
            'proveedor_id' => $supplier->id,
            'contrato_id' => null,
            'fecha_gasto' => '2026-09-20',
            'fecha_vencimiento' => $dueDate,
            'concepto' => $concept,
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
        $this->actingAs($user)->patch(route('gastos.register', [$building, $expense]))->assertSessionHasNoErrors();

        return CuentaPorPagarEloquentModel::query()->where('gasto_id', $expense->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function disbursementData(ProveedorEloquentModel $supplier, string $amount): array
    {
        return [
            'proveedor_id' => $supplier->id,
            'fecha_desembolso' => '2026-09-22',
            'monto' => $amount,
            'forma_pago' => 'transferencia',
            'referencia' => 'TRX-2026-001',
            'observacion' => 'Pago de obligaciones pendientes',
            'aplicacion_fingerprint' => str_repeat('0', 64),
        ];
    }

    /** @return array<string, mixed> */
    private function validDisbursementData(
        UserEloquentModel $user,
        EdificioEloquentModel $building,
        ProveedorEloquentModel $supplier,
        string $amount,
    ): array {
        /** @var DesembolsoRepositoryInterface $repository */
        $repository = $this->app->make(DesembolsoRepositoryInterface::class);
        $preview = $repository->preview($user->id, $building->id, $supplier->id, $amount);

        return [
            ...$this->disbursementData($supplier, $amount),
            'aplicacion_fingerprint' => $preview['aplicacionFingerprint'],
        ];
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
