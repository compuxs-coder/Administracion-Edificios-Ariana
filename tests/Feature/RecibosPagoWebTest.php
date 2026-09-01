<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Finanzas\Infrastructure\Models\CargoEloquentModel;
use Src\Finanzas\Infrastructure\Models\ConceptoCobroEloquentModel;
use Src\Finanzas\Infrastructure\Models\EvidenciaPagoEloquentModel;
use Src\Finanzas\Infrastructure\Models\PagoEloquentModel;
use Src\Finanzas\Infrastructure\Models\ReciboPagoEloquentModel;
use Src\Finanzas\Domain\Contracts\PagoRepositoryInterface;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;
use Tests\TestCase;

final class RecibosPagoWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_emits_one_receipt_snapshot_and_cancellation_annuls_it(): void
    {
        [$user, $edificio, $departamento, $concepto] = $this->fixture();
        $this->owner($edificio, $departamento);
        $this->cargo($edificio, $departamento, $concepto, '40.0000', '2026-01-31');

        $this->actingAs($user)->post(route('pagos.store', $edificio), $this->paymentData($departamento, '100.0000'))->assertSessionHasNoErrors();
        $pago = PagoEloquentModel::query()->sole();
        $recibo = ReciboPagoEloquentModel::query()->sole();

        $this->assertSame($pago->id, $recibo->pago_id);
        $this->assertSame('REC-2026-000001', $recibo->numero);
        $this->assertSame('emitido', $recibo->estado->value);
        $this->assertSame('Ana Recibo', $recibo->titulares_snapshot[0]['nombre']);
        $this->assertSame('40.0000', $recibo->aplicaciones_snapshot[0]['valorAplicado']);
        $this->actingAs($user)->get(route('recibos.show', [$edificio, $pago]))->assertInertia(fn (Assert $page) => $page
            ->component('ReciboPago/show')
            ->where('recibo.numero', 'REC-2026-000001')
            ->where('recibo.titulares.0.nombre', 'Ana Recibo')
            ->where('recibo.aplicaciones.0.valorAplicado', '40.0000'));
        $this->actingAs($user)->get(route('recibos.show', [$edificio, $pago]))->assertOk();
        $this->assertDatabaseCount('recibos_pago', 1);
        $this->assertSame(1, (int) DB::table('consecutivos_recibo')->where('anio', 2026)->value('ultimo_numero'));

        $this->cargo($edificio, $departamento, $concepto, '30.0000', '2026-02-28');
        $this->actingAs($user)->post(route('pagos.apply-credit', [$edificio, $pago]))->assertSessionHasNoErrors();
        $recibo->refresh();
        $this->assertCount(1, $recibo->aplicaciones_snapshot);
        $this->assertSame('40.0000', $recibo->aplicaciones_snapshot[0]['valorAplicado']);

        $this->actingAs($user)->patch(route('pagos.cancel', [$edificio, $pago]), ['motivo' => 'Transferencia rechazada'])->assertSessionHasNoErrors();
        $pago->refresh();
        $recibo->refresh();
        $this->assertSame('anulado', $recibo->estado->value);
        $this->assertSame('Transferencia rechazada', $recibo->motivo_anulacion);
        $this->assertSame($pago->anulado_por, $recibo->anulado_por);
        $this->assertTrue($pago->anulado_at->equalTo($recibo->anulado_at));
        $this->assertFalse(Route::has('recibos.update'));
        $this->assertFalse(Route::has('recibos.destroy'));
        $this->assertFalse(Route::has('evidencias.destroy'));
    }

    public function test_receipt_number_is_global_per_year_resets_annually_and_access_is_isolated(): void
    {
        [$owner, $edificio, $departamento] = $this->fixture();
        [$other, $otroEdificio, $otroDepartamento] = $this->fixture();

        $this->actingAs($owner)->post(route('pagos.store', $edificio), $this->paymentData($departamento, '10.0000'))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('pagos.store', $edificio), $this->paymentData($departamento, '15.0000'))->assertSessionHasNoErrors();
        $this->actingAs($other)->post(route('pagos.store', $otroEdificio), $this->paymentData($otroDepartamento, '10.0000'))->assertSessionHasNoErrors();
        $this->actingAs($other)->post(route('pagos.store', $otroEdificio), $this->paymentData($otroDepartamento, '5.0000', '2027-01-01'))->assertSessionHasNoErrors();
        $recibosEdificio = ReciboPagoEloquentModel::query()->where('edificio_id', $edificio->id)->orderBy('numero')->pluck('numero')->all();
        $recibosOtroEdificio = ReciboPagoEloquentModel::query()->where('edificio_id', $otroEdificio->id)->orderBy('numero')->pluck('numero')->all();

        $this->assertSame(['REC-2026-000001', 'REC-2026-000002'], $recibosEdificio);
        $this->assertSame(['REC-2026-000003', 'REC-2027-000001'], $recibosOtroEdificio);
        $pago = PagoEloquentModel::query()->where('edificio_id', $edificio->id)->orderBy('numero')->firstOrFail();
        $this->actingAs($other)->get(route('recibos.show', [$edificio, $pago]))->assertForbidden();
    }

    public function test_registered_payment_accepts_private_evidence_and_rejects_invalid_or_cancelled_uploads(): void
    {
        Storage::fake('evidence');
        $this->assertFalse((bool) config('filesystems.disks.evidence.serve'));
        $this->assertNotSame(config('filesystems.disks.local.root'), config('filesystems.disks.evidence.root'));
        $this->assertNotSame(config('filesystems.disks.public.root'), config('filesystems.disks.evidence.root'));
        [$user, $edificio, $departamento] = $this->fixture();
        $this->actingAs($user)->post(route('pagos.store', $edificio), $this->paymentData($departamento, '10.0000'))->assertSessionHasNoErrors();
        $pago = PagoEloquentModel::query()->sole();

        $this->actingAs($user)->post(route('evidencias.store', [$edificio, $pago]), [
            'archivo' => $this->pdf('..\transferencia.php.pdf'),
            'descripcion' => 'Comprobante bancario',
        ])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('evidencias.store', [$edificio, $pago]), ['archivo' => $this->png('captura.png')])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('evidencias.store', [$edificio, $pago]), ['archivo' => $this->jpeg('deposito.jpeg')])->assertSessionHasNoErrors();
        $evidencia = EvidenciaPagoEloquentModel::query()->orderBy('created_at')->firstOrFail();
        Storage::disk('evidence')->assertExists($evidencia->ruta_privada);
        $this->assertSame('application/pdf', $evidencia->mime_type);
        $this->assertSame('transferencia_php.pdf', $evidencia->nombre_original);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $evidencia->sha256);
        $this->actingAs($user)->get(route('recibos.show', [$edificio, $pago]))->assertInertia(fn (Assert $page) => $page
            ->where('recibo.evidencias.0.nombre', 'transferencia_php.pdf')
            ->where('recibo.evidencias.0.descripcion', 'Comprobante bancario'));
        $this->actingAs($user)->get(route('evidencias.download', [$edificio, $pago, $evidencia]))->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $original = Storage::disk('evidence')->get($evidencia->ruta_privada);
        Storage::disk('evidence')->put($evidencia->ruta_privada, 'contenido alterado');
        $this->actingAs($user)->get(route('evidencias.download', [$edificio, $pago, $evidencia]))->assertConflict();
        Storage::disk('evidence')->put($evidencia->ruta_privada, $original);
        $this->assertDatabaseCount('recibos_pago', 1);

        $this->actingAs($user)->post(route('evidencias.store', [$edificio, $pago]), [
            'archivo' => UploadedFile::fake()->createWithContent('falso.pdf', 'MZ ejecutable'),
        ])->assertSessionHasErrors('archivo');
        $this->actingAs($user)->post(route('evidencias.store', [$edificio, $pago]), [
            'archivo' => UploadedFile::fake()->create('grande.pdf', 10241, 'application/pdf'),
        ])->assertSessionHasErrors('archivo');
        $this->actingAs($user)->patch(route('pagos.cancel', [$edificio, $pago]), ['motivo' => 'Cheque rechazado'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('evidencias.store', [$edificio, $pago]), [
            'archivo' => $this->pdf('tardio.pdf'),
        ])->assertSessionHasErrors('pago');
        $this->assertDatabaseCount('evidencias_pago', 3);
        Storage::disk('evidence')->assertExists($evidencia->ruta_privada);
    }

    public function test_receipt_and_evidence_routes_require_authorization_and_nested_resources_are_scoped(): void
    {
        Storage::fake('evidence');
        [$owner, $edificio, $departamento] = $this->fixture();
        [$other, $otroEdificio, $otroDepartamento] = $this->fixture();
        $this->actingAs($owner)->post(route('pagos.store', $edificio), $this->paymentData($departamento, '10.0000'))->assertSessionHasNoErrors();
        $firstPayment = PagoEloquentModel::query()->where('edificio_id', $edificio->id)->sole();
        $this->actingAs($owner)->post(route('evidencias.store', [$edificio, $firstPayment]), ['archivo' => $this->pdf('pago.pdf')])->assertSessionHasNoErrors();
        $evidence = EvidenciaPagoEloquentModel::query()->sole();
        $this->actingAs($owner)->post(route('pagos.store', $edificio), $this->paymentData($departamento, '5.0000'))->assertSessionHasNoErrors();
        $secondPayment = PagoEloquentModel::query()->where('edificio_id', $edificio->id)->orderByDesc('numero')->firstOrFail();
        $this->actingAs($other)->post(route('pagos.store', $otroEdificio), $this->paymentData($otroDepartamento, '10.0000'))->assertSessionHasNoErrors();

        auth()->logout();
        $this->get(route('recibos.show', [$edificio, $firstPayment]))->assertRedirect(route('login'));
        $this->post(route('evidencias.store', [$edificio, $firstPayment]), ['archivo' => $this->pdf('anonimo.pdf')])->assertRedirect(route('login'));
        $this->get(route('evidencias.download', [$edificio, $firstPayment, $evidence]))->assertRedirect(route('login'));
        $this->actingAs($other)->get(route('recibos.show', [$edificio, $firstPayment]))->assertForbidden();
        $this->actingAs($other)->get(route('evidencias.download', [$edificio, $firstPayment, $evidence]))->assertForbidden();
        $this->actingAs($owner)->get(route('evidencias.download', [$edificio, $secondPayment, $evidence]))->assertNotFound();
    }

    public function test_receipt_creation_and_cancellation_failures_roll_back_financial_mutations(): void
    {
        [$user, $edificio, $departamento, $concepto] = $this->fixture();
        $this->cargo($edificio, $departamento, $concepto, '50.0000', '2026-01-31');
        $failCreation = true;
        ReciboPagoEloquentModel::creating(static function () use (&$failCreation): void {
            if ($failCreation) {
                throw new \RuntimeException('Fallo de recibo simulado.');
            }
        });
        try {
            $this->app->make(PagoRepositoryInterface::class)->create($user->id, $edificio->id, $this->paymentData($departamento, '50.0000'));
            $this->fail('La emisión debía interrumpir la transacción.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fallo de recibo simulado.', $exception->getMessage());
        } finally {
            $failCreation = false;
        }
        $cargo = CargoEloquentModel::query()->sole();
        $this->assertSame('50.0000', $cargo->saldo);
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('aplicaciones_pago', 0);
        $this->assertDatabaseCount('recibos_pago', 0);
        $this->assertDatabaseCount('consecutivos_recibo', 0);

        $this->app->make(PagoRepositoryInterface::class)->create($user->id, $edificio->id, $this->paymentData($departamento, '50.0000'));
        $payment = PagoEloquentModel::query()->sole();
        $receipt = ReciboPagoEloquentModel::query()->sole();
        $failCancellation = true;
        ReciboPagoEloquentModel::updating(static function () use (&$failCancellation): void {
            if ($failCancellation) {
                throw new \RuntimeException('Fallo de anulación simulado.');
            }
        });
        try {
            $this->app->make(PagoRepositoryInterface::class)->cancel($user->id, $edificio->id, $payment->id, 'Prueba rollback');
            $this->fail('La anulación debía revertirse completamente.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fallo de anulación simulado.', $exception->getMessage());
        } finally {
            $failCancellation = false;
        }
        $this->assertSame('registrado', $payment->fresh()->estado->value);
        $this->assertSame('emitido', $receipt->fresh()->estado->value);
        $this->assertSame('0.0000', $cargo->fresh()->saldo);
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel, DepartamentoEloquentModel, ConceptoCobroEloquentModel} */
    private function fixture(): array
    {
        $user = UserEloquentModel::factory()->create();
        /** @var CreateEdificioAction $action */
        $action = $this->app->make(CreateEdificioAction::class);
        $created = $action->execute(['nombre' => 'Edificio '.Str::upper(Str::random(6)), 'ruc' => Str::upper(Str::random(13)), 'direccion' => 'Av. Recibos 123', 'ciudad' => 'Quito', 'telefono' => null, 'correo' => null, 'responsable' => null], $user->id);
        $edificio = EdificioEloquentModel::query()->findOrFail($created->id());
        $piso = PisoEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'torre_id' => $edificio->torres()->sole()->id, 'numero' => '1', 'nombre' => 'Primer piso', 'orden' => 1, 'estado' => 'activo']);
        $departamento = DepartamentoEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'piso_id' => $piso->id, 'codigo' => 'R-101', 'nombre' => 'Departamento recibo', 'alicuota' => '5.000000', 'estado' => 'activo']);
        $concepto = ConceptoCobroEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'codigo' => 'REC', 'nombre' => 'Cuota de recibo', 'tipo' => 'ordinario', 'periodicidad' => 'mensual', 'forma_calculo' => 'valor_fijo', 'estado' => 'activo']);

        return [$user, $edificio, $departamento, $concepto];
    }

    private function cargo(EdificioEloquentModel $edificio, DepartamentoEloquentModel $departamento, ConceptoCobroEloquentModel $concepto, string $valor, string $vencimiento): void
    {
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $vencimiento);
        CargoEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'departamento_id' => $departamento->id, 'concepto_cobro_id' => $concepto->id, 'periodo' => $fecha->startOfMonth(), 'fecha_emision' => $fecha->startOfMonth(), 'fecha_vencimiento' => $fecha, 'descripcion' => 'Cargo '.$vencimiento, 'valor_original' => $valor, 'saldo' => $valor, 'estado' => 'pendiente', 'origen' => 'manual', 'metadata' => []]);
    }

    private function owner(EdificioEloquentModel $edificio, DepartamentoEloquentModel $departamento): void
    {
        $propietario = PropietarioEloquentModel::query()->forceCreate(['tipo_persona' => 'persona_natural', 'nombres' => 'Ana', 'apellidos' => 'Recibo', 'tipo_identificacion' => 'cedula', 'identificacion' => Str::random(10), 'estado' => 'activo']);
        $propietario->edificios()->attach($edificio->id);
        DepartamentoPropietarioEloquentModel::query()->forceCreate(['edificio_id' => $edificio->id, 'departamento_id' => $departamento->id, 'propietario_id' => $propietario->id, 'nombre_propietario' => 'Ana Recibo', 'tipo_identificacion_snapshot' => 'cedula', 'identificacion_snapshot' => $propietario->identificacion, 'porcentaje' => '100.000000', 'fecha_inicio' => '2025-01-01', 'fecha_fin' => null, 'estado' => 'activa']);
    }

    /** @return array<string, mixed> */
    private function paymentData(DepartamentoEloquentModel $departamento, string $valor, string $fecha = '2026-02-01'): array
    {
        return ['departamento_id' => $departamento->id, 'fecha_pago' => $fecha, 'valor_recibido' => $valor, 'forma_pago' => 'efectivo', 'referencia' => null, 'observacion' => null];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    }

    private function jpeg(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=', true));
    }
}
