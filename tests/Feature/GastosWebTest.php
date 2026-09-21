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
use Src\Gastos\Domain\Contracts\GastoRepositoryInterface;
use Src\Gastos\Domain\Enums\EstadoContratoProveedor;
use Src\Gastos\Domain\Enums\EstadoCuentaPorPagar;
use Src\Gastos\Domain\Enums\EstadoGasto;
use Src\Gastos\Domain\Enums\EstadoProveedor;
use Src\Gastos\Infrastructure\Models\ContratoProveedorEloquentModel;
use Src\Gastos\Infrastructure\Models\CuentaPorPagarEloquentModel;
use Src\Gastos\Infrastructure\Models\GastoEloquentModel;
use Src\Gastos\Infrastructure\Models\ProveedorEdificioEloquentModel;
use Src\Gastos\Infrastructure\Models\ProveedorEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;
use Tests\TestCase;

final class GastosWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_require_authentication_and_expose_no_destructive_operations(): void
    {
        [, $building] = $this->fixture();
        $resourceId = (string) Str::uuid();

        $this->get(route('proveedores.index'))->assertRedirect(route('login'));
        $this->get(route('proveedores.create'))->assertRedirect(route('login'));
        $this->post(route('proveedores.store', $building), $this->supplierData())->assertRedirect(route('login'));
        $this->get(route('contratos-proveedor.index'))->assertRedirect(route('login'));
        $this->get(route('gastos.index'))->assertRedirect(route('login'));
        $this->get(route('cuentas-por-pagar.index'))->assertRedirect(route('login'));
        $this->get(route('gastos.show', [$building, $resourceId]))->assertRedirect(route('login'));

        $this->assertFalse(Route::has('proveedores.destroy'));
        $this->assertFalse(Route::has('contratos-proveedor.destroy'));
        $this->assertFalse(Route::has('gastos.destroy'));
        $this->assertFalse(Route::has('cuentas-por-pagar.destroy'));
    }

    public function test_credit_expense_workflow_preserves_snapshots_and_cancels_its_account_payable(): void
    {
        [$user, $building] = $this->fixture();

        $this->actingAs($user)->get(route('proveedores.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Proveedor/create')
                ->has('edificios', 1));
        $this->actingAs($user)->post(route('proveedores.store', $building), $this->supplierData())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $supplier = ProveedorEloquentModel::query()->sole();
        $association = ProveedorEdificioEloquentModel::query()->sole();

        $this->actingAs($user)->get(route('proveedores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Proveedor/index')
                ->has('proveedores.data', 1)
                ->where('proveedores.data.0.id', $supplier->id));
        $this->actingAs($user)->put(route('proveedores.update', [$building, $supplier]), [
            'nombre_comercial' => 'Ascensores Norte',
            'contacto' => 'María Operaciones',
            'telefono_comercial' => '022222222',
            'correo_comercial' => 'operaciones@example.test',
            'direccion_comercial' => 'Av. Comercial 456',
            'dias_credito' => 45,
            'observaciones' => 'Atención prioritaria',
        ])->assertSessionHasNoErrors();
        $association->refresh();
        $this->assertSame('Ascensores Norte', $association->nombre_comercial);
        $this->assertSame(45, $association->dias_credito);

        $this->actingAs($user)->post(route('contratos-proveedor.store', $building), $this->contractData($supplier))
            ->assertSessionHasNoErrors();
        $contract = ContratoProveedorEloquentModel::query()->sole();
        $this->assertSame(EstadoContratoProveedor::BORRADOR, $contract->estado);
        $this->actingAs($user)->patch(route('contratos-proveedor.register', [$building, $contract]))
            ->assertSessionHasNoErrors();
        $contract->refresh();
        $this->assertSame(EstadoContratoProveedor::REGISTRADO, $contract->estado);
        $this->assertSame('Ascensores Norte', $contract->proveedor_snapshot['nombreComercial']);

        $this->actingAs($user)->post(route('gastos.store', $building), $this->expenseData($supplier, $contract))
            ->assertSessionHasNoErrors();
        $expense = GastoEloquentModel::query()->sole();
        $this->assertSame(EstadoGasto::BORRADOR, $expense->estado);
        $this->actingAs($user)->patch(route('gastos.register', [$building, $expense]))
            ->assertSessionHasNoErrors();
        $expense->refresh();
        $account = CuentaPorPagarEloquentModel::query()->sole();

        $this->assertSame(EstadoGasto::REGISTRADO, $expense->estado);
        $this->assertSame('GAS-2026-000001', $expense->numero);
        $this->assertSame('pendiente', $expense->estado_pago->value);
        $this->assertSame($contract->id, $expense->contrato_snapshot['contratoId']);
        $this->assertSame($expense->id, $account->gasto_id);
        $this->assertSame('125.5000', $account->saldo);

        $this->actingAs($user)->get(route('gastos.show', [$building, $expense]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Gasto/show')
                ->where('gasto.numero', 'GAS-2026-000001')
                ->where('gasto.cuentaPorPagarId', $account->id));
        $this->actingAs($user)->get(route('cuentas-por-pagar.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('CuentaPorPagar/index')
                ->has('cuentasPorPagar.data', 1)
                ->where('resumen.totalPendiente', '125.5000')
                ->where('resumen.porVencer', '125.5000'));

        $this->actingAs($user)->patch(route('gastos.cancel', [$building, $expense]), [
            'motivo' => 'Documento emitido por error',
        ])->assertSessionHasNoErrors();
        $expense->refresh();
        $account->refresh();
        $this->assertSame(EstadoGasto::ANULADO, $expense->estado);
        $this->assertSame('anulado', $expense->estado_pago->value);
        $this->assertSame(EstadoCuentaPorPagar::ANULADA, $account->estado);
        $this->assertSame('Documento emitido por error', $expense->motivo_anulacion);

        $this->assertDatabaseRejects(static fn () => DB::table('gastos')->where('id', $expense->id)->update(['concepto' => 'Alterado']));
        $this->assertDatabaseRejects(static fn () => DB::table('cuentas_por_pagar')->where('id', $account->id)->delete());
    }

    public function test_cash_expense_is_paid_without_creating_an_account_payable(): void
    {
        [$user, $building] = $this->fixture();
        $supplier = $this->createSupplier($user, $building);

        $this->actingAs($user)->post(route('gastos.store', $building), $this->expenseData($supplier, null, [
            'tipo_pago' => 'contado',
            'fecha_vencimiento' => null,
            'monto' => '80.0000',
        ]))->assertSessionHasNoErrors();
        $expense = GastoEloquentModel::query()->sole();
        $this->actingAs($user)->patch(route('gastos.register', [$building, $expense]))
            ->assertSessionHasNoErrors();
        $expense->refresh();

        $this->assertSame('pagado', $expense->estado_pago->value);
        $this->assertNotNull($expense->pagado_at);
        $this->assertDatabaseCount('cuentas_por_pagar', 0);
    }

    public function test_supplier_identity_is_global_but_commercial_status_is_local_to_each_building(): void
    {
        [$user, $firstBuilding] = $this->fixture();
        $secondBuilding = $this->buildingFor($user);
        $identity = $this->supplierData();

        $this->actingAs($user)->post(route('proveedores.store', $firstBuilding), $identity)
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('proveedores.store', $secondBuilding), [
            ...$identity,
            'razon_social' => 'Identidad distinta',
        ])->assertSessionHasErrors('identificacion');
        $this->actingAs($user)->post(route('proveedores.store', $secondBuilding), [
            ...$identity,
            'nombre_comercial' => 'Nombre local del segundo edificio',
        ])->assertSessionHasNoErrors();

        $supplier = ProveedorEloquentModel::query()->sole();
        $this->assertDatabaseCount('terceros', 1);
        $this->assertDatabaseCount('proveedores', 1);
        $this->assertDatabaseCount('proveedor_edificio', 2);

        $this->actingAs($user)->patch(route('proveedores.estado', [$firstBuilding, $supplier]), [
            'estado' => 'inactivo',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('proveedor_edificio', [
            'edificio_id' => $firstBuilding->id,
            'proveedor_id' => $supplier->id,
            'estado' => EstadoProveedor::INACTIVO->value,
        ]);
        $this->assertDatabaseHas('proveedor_edificio', [
            'edificio_id' => $secondBuilding->id,
            'proveedor_id' => $supplier->id,
            'estado' => EstadoProveedor::ACTIVO->value,
        ]);
        $this->assertDatabaseRejects(static fn () => DB::table('proveedores')->where('id', $supplier->id)->update([
            'tercero_id' => (string) Str::uuid(),
        ]));
    }

    public function test_data_and_mutations_are_isolated_by_building(): void
    {
        [$firstUser, $firstBuilding] = $this->fixture();
        [$secondUser, $secondBuilding] = $this->fixture();
        $firstSupplier = $this->createSupplier($firstUser, $firstBuilding, ['identificacion' => '1790000000001']);
        $secondSupplier = $this->createSupplier($secondUser, $secondBuilding, ['identificacion' => '1790000000002']);

        $this->actingAs($firstUser)->get(route('proveedores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('proveedores.data', 1)
                ->where('proveedores.data.0.id', $firstSupplier->id));
        $this->actingAs($firstUser)->get(route('proveedores.show', [$secondBuilding, $secondSupplier]))
            ->assertNotFound();
        $this->actingAs($firstUser)->post(route('contratos-proveedor.store', $firstBuilding), $this->contractData($secondSupplier))
            ->assertSessionHasErrors('proveedorId');
        $this->assertDatabaseCount('contratos_proveedor', 0);
    }

    public function test_role_matrix_allows_finance_management_and_read_only_consultation(): void
    {
        [$administrator, $building] = $this->fixture();
        $financeManager = UserEloquentModel::factory()->create();
        $viewer = UserEloquentModel::factory()->create();
        $propertyManager = UserEloquentModel::factory()->create();
        $this->addMember($building, $financeManager, RolEdificio::GESTOR_FINANZAS, $administrator);
        $this->addMember($building, $viewer, RolEdificio::CONSULTA, $administrator);
        $this->addMember($building, $propertyManager, RolEdificio::GESTOR_PROPIEDAD, $administrator);

        $this->actingAs($viewer)->get(route('gastos.index'))->assertOk();
        $this->actingAs($viewer)->get(route('gastos.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('proveedores.store', $building), $this->supplierData())
            ->assertForbidden();
        $this->actingAs($propertyManager)->get(route('proveedores.index'))->assertForbidden();
        $this->actingAs($financeManager)->post(route('proveedores.store', $building), $this->supplierData())
            ->assertSessionHasNoErrors();
    }

    public function test_shared_identity_cannot_be_edited_without_access_to_its_supplier_building(): void
    {
        [$administrator, $propertyBuilding] = $this->fixture();
        $supplierBuilding = $this->buildingFor($administrator);
        $propertyManager = UserEloquentModel::factory()->create();
        $this->addMember($propertyBuilding, $propertyManager, RolEdificio::GESTOR_PROPIEDAD, $administrator);
        $identity = [
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
        ];

        $this->actingAs($administrator)->post(route('propietarios.store', $propertyBuilding), $identity)
            ->assertSessionHasNoErrors();
        $owner = PropietarioEloquentModel::query()->sole();
        $this->actingAs($administrator)->post(route('proveedores.store', $supplierBuilding), [
            ...$identity,
            'nombre_comercial' => 'Servicios Ana',
            'contacto' => null,
            'telefono_comercial' => null,
            'correo_comercial' => null,
            'direccion_comercial' => null,
            'dias_credito' => null,
        ])->assertSessionHasNoErrors();

        $this->actingAs($propertyManager)->put(route('propietarios.update', $owner), [
            ...$identity,
            'nombres' => 'Nombre alterado',
        ])->assertForbidden();
        $this->assertSame('Ana María', $owner->fresh()->tercero->nombres);
        $this->actingAs($propertyManager)->get(route('propietarios.show', $owner))
            ->assertInertia(fn (Assert $page) => $page
                ->where('propietario.puedeGestionar', true)
                ->where('propietario.puedeEditarIdentidad', false));
    }

    public function test_existing_supplier_identity_can_be_reused_as_an_owner_profile(): void
    {
        [$administrator, $building] = $this->fixture();
        $identity = [
            'tipo_persona' => 'persona_natural',
            'nombres' => 'Bruno',
            'apellidos' => 'Proveedor',
            'razon_social' => null,
            'tipo_identificacion' => 'cedula',
            'identificacion' => '1711111111',
            'telefono' => '022345000',
            'celular' => '0991000000',
            'correo' => 'bruno@example.test',
            'direccion' => 'Av. Compartida 10',
            'observaciones' => null,
        ];

        $this->actingAs($administrator)->post(route('proveedores.store', $building), [
            ...$identity,
            'nombre_comercial' => 'Bruno Servicios',
            'contacto' => null,
            'telefono_comercial' => null,
            'correo_comercial' => null,
            'direccion_comercial' => null,
            'dias_credito' => null,
        ])->assertSessionHasNoErrors();
        $this->actingAs($administrator)->post(route('propietarios.store', $building), $identity)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('terceros', 1);
        $this->assertDatabaseCount('proveedores', 1);
        $this->assertDatabaseCount('propietarios', 1);
        $this->assertSame(
            ProveedorEloquentModel::query()->sole()->tercero_id,
            PropietarioEloquentModel::query()->sole()->tercero_id,
        );
    }

    public function test_failed_account_creation_rolls_back_expense_registration_and_number(): void
    {
        [$user, $building] = $this->fixture();
        $supplier = $this->createSupplier($user, $building);
        $this->actingAs($user)->post(route('gastos.store', $building), $this->expenseData($supplier))
            ->assertSessionHasNoErrors();
        $expense = GastoEloquentModel::query()->sole();
        $dispatcher = CuentaPorPagarEloquentModel::getEventDispatcher();
        CuentaPorPagarEloquentModel::creating(static function (): void {
            throw new \RuntimeException('Fallo simulado al crear la cuenta por pagar.');
        });

        try {
            /** @var GastoRepositoryInterface $repository */
            $repository = $this->app->make(GastoRepositoryInterface::class);
            $repository->register($user->id, $building->id, $expense->id);
            $this->fail('El registro debía revertirse por completo.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fallo simulado al crear la cuenta por pagar.', $exception->getMessage());
        } finally {
            CuentaPorPagarEloquentModel::setEventDispatcher($dispatcher);
        }

        $expense->refresh();
        $this->assertSame(EstadoGasto::BORRADOR, $expense->estado);
        $this->assertNull($expense->numero);
        $this->assertDatabaseCount('cuentas_por_pagar', 0);
        $this->assertDatabaseCount('consecutivos_gasto', 0);
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

    private function createSupplier(
        UserEloquentModel $user,
        EdificioEloquentModel $building,
        array $overrides = [],
    ): ProveedorEloquentModel {
        $data = $this->supplierData($overrides);
        $this->actingAs($user)->post(route('proveedores.store', $building), $data)
            ->assertSessionHasNoErrors();

        return ProveedorEloquentModel::query()
            ->whereHas('tercero', static fn ($query) => $query
                ->where('tipo_identificacion', $data['tipo_identificacion'])
                ->where('identificacion', $data['identificacion']))
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function supplierData(array $overrides = []): array
    {
        return array_merge([
            'tipo_persona' => 'persona_juridica',
            'nombres' => null,
            'apellidos' => null,
            'razon_social' => 'Servicios Verticales SA',
            'tipo_identificacion' => 'ruc',
            'identificacion' => '1791234567001',
            'telefono' => '022111111',
            'celular' => null,
            'correo' => 'info@example.test',
            'direccion' => 'Av. Identidad 100',
            'nombre_comercial' => 'Verticales',
            'contacto' => 'Ana Técnica',
            'telefono_comercial' => '0999999999',
            'correo_comercial' => 'ventas@example.test',
            'direccion_comercial' => 'Av. Comercial 123',
            'dias_credito' => 30,
            'observaciones' => null,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function contractData(ProveedorEloquentModel $supplier, array $overrides = []): array
    {
        return array_merge([
            'proveedor_id' => $supplier->id,
            'referencia' => 'CONT-001',
            'objeto' => 'Mantenimiento preventivo de ascensores',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-12-31',
            'monto_total' => '1500.0000',
            'observaciones' => null,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function expenseData(
        ProveedorEloquentModel $supplier,
        ?ContratoProveedorEloquentModel $contract = null,
        array $overrides = [],
    ): array {
        return array_merge([
            'proveedor_id' => $supplier->id,
            'contrato_id' => $contract?->id,
            'fecha_gasto' => '2026-09-18',
            'fecha_vencimiento' => '2099-12-31',
            'concepto' => 'Mantenimiento mensual',
            'referencia' => 'FAC-001',
            'monto' => '125.5000',
            'tipo_pago' => 'credito',
            'observaciones' => null,
        ], $overrides);
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
