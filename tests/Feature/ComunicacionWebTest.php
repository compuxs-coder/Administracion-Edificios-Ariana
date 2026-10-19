<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Comunicacion\Application\Actions\EnviarEntregaComunicadoAction;
use Src\Comunicacion\Application\Actions\CleanupOrphanedComunicadoDocumentsAction;
use Src\Comunicacion\Domain\Contracts\ComunicacionRepositoryInterface;
use Src\Comunicacion\Infrastructure\Jobs\DespacharEntregasComunicadoJob;
use Src\Comunicacion\Infrastructure\Jobs\EnviarEntregaComunicadoJob;
use Src\Comunicacion\Infrastructure\Models\BitacoraComunicacionEloquentModel;
use Src\Comunicacion\Infrastructure\Models\ComunicadoEloquentModel;
use Src\Comunicacion\Infrastructure\Models\DestinatarioComunicadoEloquentModel;
use Src\Comunicacion\Infrastructure\Models\DocumentoComunicadoEloquentModel;
use Src\Comunicacion\Infrastructure\Models\EntregaComunicadoEloquentModel;
use Src\Comunicacion\Infrastructure\Models\LimpiezaDocumentoComunicacionEloquentModel;
use Src\Comunicacion\Infrastructure\Notifications\ComunicadoPublicadoNotification;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\PisoEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoPropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\DepartamentoResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;
use Src\Propiedad\Infrastructure\Models\TerceroEloquentModel;
use Tests\TestCase;

final class ComunicacionWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00 UTC');
        Storage::fake('communications');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_postgresql_connection_uses_utc_for_delivery_timestamps(): void
    {
        $this->assertSame('UTC', config('database.connections.pgsql.timezone'));
    }

    public function test_routes_enforce_role_matrix_and_building_isolation_without_exposing_deliveries(): void
    {
        [$administrator, $building, $department] = $this->fixture();
        [$otherAdministrator, $otherBuilding] = $this->fixture();
        $manager = UserEloquentModel::factory()->create();
        $viewer = UserEloquentModel::factory()->create();
        $propertyManager = UserEloquentModel::factory()->create();
        $this->addMember($building, $manager, RolEdificio::GESTOR_COMUNICACION, $administrator);
        $this->addMember($building, $viewer, RolEdificio::CONSULTA, $administrator);
        $this->addMember($building, $propertyManager, RolEdificio::GESTOR_PROPIEDAD, $administrator);

        $this->get(route('comunicados.index'))->assertRedirect(route('login'));
        $this->actingAs($manager)->get(route('comunicados.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Comunicado/index')->has('edificios', 1)->where('edificios.0.id', $building->id));
        $this->actingAs($viewer)->get(route('comunicados.index'))->assertOk();
        $this->actingAs($viewer)->get(route('comunicados.create'))->assertForbidden();
        $this->actingAs($propertyManager)->get(route('comunicados.index'))->assertForbidden();

        $message = $this->createMessage($administrator, $building);
        $this->ownerFor($building, $department, '1710000001', 'destino@example.test');
        $this->ownerFor($building, $department, '1710000002', 'segundo@example.test');
        $this->publish($administrator, $building, $message);
        $this->actingAs($viewer)->get(route('comunicados.show', [$building, $message]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('comunicado.puedeGestionar', false)
                ->where('comunicado.puedePublicar', false)
                ->where('comunicado.puedeVerEntregas', false)
                ->missing('comunicado.totalCandidatos')
                ->missing('comunicado.totalEntregas')
                ->missing('comunicado.totalOmitidos')
                ->missing('comunicado.resumenEntregas')
                ->missing('comunicado.entregas')
                ->missing('comunicado.omitidos'));
        config(['comunicacion.trace_page_size' => 1]);
        $this->actingAs($manager)->get(route('comunicados.show', [$building, $message]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('comunicado.puedeVerEntregas', true)
                ->has('comunicado.entregas', 1)->where('comunicado.entregasMeta.total', 2)->where('comunicado.entregasMeta.lastPage', 2));
        $this->actingAs($manager)->get(route('comunicados.show', [
            'edificio' => $building, 'comunicado' => $message, 'entregas_page' => 2,
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('comunicado.entregasMeta.currentPage', 2)->has('comunicado.entregas', 1));
        $viewerDetail = app(ComunicacionRepositoryInterface::class)->get($viewer->id, $building->id, $message->id);
        $publishedEvent = collect($viewerDetail['bitacora'])->firstWhere('tipo', 'comunicado_publicado');
        $this->assertArrayNotHasKey('entregas', $publishedEvent['detalle']);
        $this->assertArrayNotHasKey('omitidos', $publishedEvent['detalle']);
        $this->actingAs($otherAdministrator)->get(route('comunicados.show', [$building, $message]))->assertNotFound();
        $this->actingAs($administrator)->get(route('comunicados.show', [$otherBuilding, $message]))->assertNotFound();

        $access = app(AccesoEdificioRepositoryInterface::class);
        foreach ([PermisoEdificio::COMUNICADOS_VER, PermisoEdificio::COMUNICADOS_GESTIONAR, PermisoEdificio::COMUNICADOS_PUBLICAR, PermisoEdificio::COMUNICADOS_ENTREGAS_VER] as $permission) {
            $this->assertTrue($access->hasPermission($manager->id, $building->id, $permission));
        }
        $this->assertTrue($access->hasPermission($viewer->id, $building->id, PermisoEdificio::COMUNICADOS_VER));
        $this->assertFalse($access->hasPermission($viewer->id, $building->id, PermisoEdificio::COMUNICADOS_ENTREGAS_VER));
    }

    public function test_draft_is_normalized_versioned_validated_and_audited(): void
    {
        [$administrator, $building] = $this->fixture();
        $this->actingAs($administrator)->post(route('comunicados.store', $building), [
            'titulo' => '  Aviso general  ',
            'cuerpo' => "  Primera línea.\nSegunda línea.  ",
            'audiencias' => ['propietarios', 'propietarios'],
        ])->assertSessionHasErrors(['audiencias.0', 'audiencias.1']);
        $this->actingAs($administrator)->post(route('comunicados.store', $building), [
            'titulo' => '  Aviso general  ',
            'cuerpo' => "  Primera línea.\nSegunda línea.  ",
            'audiencias' => ['propietarios'],
        ])->assertSessionHasNoErrors();
        $message = ComunicadoEloquentModel::query()->sole();
        $this->assertSame('Aviso general', $message->titulo);
        $this->assertSame("Primera línea.\nSegunda línea.", $message->cuerpo);
        $this->assertSame(1, $message->version);
        $this->assertDatabaseHas('bitacora_comunicacion', ['comunicado_id' => $message->id, 'tipo' => 'comunicado_creado']);

        $stale = $message->updated_at?->toIso8601String();
        $this->actingAs($administrator)->put(route('comunicados.update', [$building, $message]), [
            'titulo' => 'Aviso actualizado',
            'cuerpo' => $message->cuerpo,
            'audiencias' => ['propietarios', 'residentes'],
            'updated_at' => $stale,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $message->refresh()->version);
        $this->actingAs($administrator)->put(route('comunicados.update', [$building, $message]), [
            'titulo' => 'Sobrescritura obsoleta',
            'cuerpo' => $message->cuerpo,
            'audiencias' => ['residentes'],
            'updated_at' => $stale,
        ])->assertSessionHasErrors(['updatedAt']);
        $this->assertSame('Aviso actualizado', $message->refresh()->titulo);
        $this->assertDatabaseHas('bitacora_comunicacion', ['comunicado_id' => $message->id, 'tipo' => 'comunicado_actualizado']);

        $viewer = UserEloquentModel::factory()->create();
        $this->addMember($building, $viewer, RolEdificio::CONSULTA, $administrator);
        $viewerDetail = app(ComunicacionRepositoryInterface::class)->get($viewer->id, $building->id, $message->id);
        $viewerUpdate = collect($viewerDetail['bitacora'])->firstWhere('tipo', 'comunicado_actualizado');
        $this->assertSame(['titulo', 'audiencias'], array_keys($viewerUpdate['detalle']['cambios']));
        $this->assertStringNotContainsString('Aviso general', json_encode($viewerUpdate, JSON_THROW_ON_ERROR));
    }

    public function test_publication_resolves_local_active_audience_deduplicates_email_and_freezes_snapshots(): void
    {
        [$administrator, $building, $department] = $this->fixture();
        $message = $this->createMessage($administrator, $building, ['audiencias' => ['propietarios', 'residentes']]);
        $owner = $this->ownerFor($building, $department, '1710000011', '  COMUNIDAD@Example.Test ');
        $this->residentFor($building, $department, $owner->tercero, '1710000011');
        $this->residentFor($building, $department, null, '1710000012', 'comunidad@example.test');
        $this->residentFor($building, $department, null, '1710000013', null);
        $this->residentFor($building, $department, null, '1710000014', 'futuro@example.test', '2026-10-16');

        $this->actingAs($administrator)->getJson(route('comunicados.recipients.preview', [$building, $message]))
            ->assertOk()->assertExactJson([
                'candidatos' => 3,
                'propietarios' => 1,
                'residentes' => 3,
                'entregas' => 1,
                'omitidos' => 1,
                'duplicados' => 1,
            ]);
        $this->publish($administrator, $building, $message);

        $message->refresh();
        $this->assertSame('publicado', $message->estado->value);
        $this->assertSame(3, $message->total_candidatos);
        $this->assertSame(1, $message->total_entregas);
        $this->assertSame(1, $message->total_omitidos);
        $this->assertDatabaseCount('entregas_comunicado', 1);
        $this->assertDatabaseCount('destinatarios_comunicado', 3);
        $delivery = EntregaComunicadoEloquentModel::query()->sole();
        $this->assertSame('comunidad@example.test', $delivery->destino_normalizado);
        $this->assertSame(2, DestinatarioComunicadoEloquentModel::query()->where('entrega_id', $delivery->id)->count());
        $this->assertDatabaseHas('destinatarios_comunicado', ['tercero_id' => $owner->tercero_id, 'es_propietario' => true, 'es_residente' => true]);
        $this->assertDatabaseHas('destinatarios_comunicado', ['estado' => 'omitido', 'motivo_omision' => 'sin_correo']);

        $owner->tercero->update(['correo' => 'nuevo@example.test', 'nombres' => 'Nombre cambiado']);
        $snapshot = DestinatarioComunicadoEloquentModel::query()->where('tercero_id', $owner->tercero_id)->sole();
        $this->assertSame('COMUNIDAD@Example.Test', $snapshot->email_snapshot);
        $this->assertSame('Propietario 1710000011', $snapshot->nombre_snapshot);
    }

    public function test_private_documents_are_verified_and_signed_downloads_are_delivery_bound(): void
    {
        [$administrator, $building, $department] = $this->fixture();
        $message = $this->createMessage($administrator, $building);
        $this->ownerFor($building, $department, '1710000021', 'adjunto@example.test');
        $file = $this->pngFile('aviso original.png');
        $this->actingAs($administrator)->post(route('comunicados.documents.store', [$building, $message]), [
            'archivo' => $file,
            'descripcion' => 'Plano de referencia',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $document = DocumentoComunicadoEloquentModel::query()->sole();
        $this->assertSame('communications', $document->disco);
        Storage::disk('communications')->assertExists($document->ruta_privada);
        $this->assertSame(hash('sha256', Storage::disk('communications')->get($document->ruta_privada)), $document->sha256);
        LimpiezaDocumentoComunicacionEloquentModel::query()->create([
            'disco' => 'alias-del-mismo-disco', 'ruta_privada' => $document->ruta_privada, 'created_at' => now()->subDays(2),
        ]);
        $liveCleanup = app(CleanupOrphanedComunicadoDocumentsAction::class)->execute();
        $this->assertSame(0, $liveCleanup['deleted']);
        Storage::disk('communications')->assertExists($document->ruta_privada);
        $orphan = 'comunicacion/orphan-smoke.png';
        Storage::disk('communications')->put($orphan, $this->pngContents());
        LimpiezaDocumentoComunicacionEloquentModel::query()->create([
            'disco' => 'communications', 'ruta_privada' => $orphan, 'created_at' => now()->subDays(2),
        ]);
        $cleanup = app(CleanupOrphanedComunicadoDocumentsAction::class)->execute();
        $this->assertSame(1, $cleanup['deleted']);
        Storage::disk('communications')->assertMissing($orphan);
        $this->assertDatabaseCount('limpieza_documentos_comunicacion', 0);
        $this->assertDatabaseRejects(static fn () => DB::table('documentos_comunicado')->insert([
            'id' => (string) Str::uuid(), 'edificio_id' => $building->id, 'comunicado_id' => $message->id,
            'nombre_original' => 'hash-invalido.png', 'mime_type' => 'image/png', 'tamano_bytes' => 1,
            'sha256' => str_repeat('z', 64), 'ruta_privada' => 'comunicacion/hash-invalido.png',
            'descripcion' => null, 'subido_por_user_id' => $administrator->id, 'created_at' => now(),
        ]));
        $this->assertDatabaseRejects(static fn () => DB::table('documentos_comunicado')->insert([
            'id' => (string) Str::uuid(), 'edificio_id' => $building->id, 'comunicado_id' => $message->id,
            'disco' => '', 'nombre_original' => 'sin-disco.png', 'mime_type' => 'image/png', 'tamano_bytes' => 1,
            'sha256' => str_repeat('a', 64), 'ruta_privada' => 'comunicacion/sin-disco.png',
            'descripcion' => null, 'subido_por_user_id' => $administrator->id, 'created_at' => now(),
        ]));
        $this->actingAs($administrator)->post(route('comunicados.documents.store', [$building, $message]), [
            'archivo' => $this->pngFile('ausente.png'),
        ])->assertSessionHasNoErrors();
        $missing = DocumentoComunicadoEloquentModel::query()->whereKeyNot($document->id)->sole();
        Storage::disk('communications')->delete($missing->ruta_privada);
        $this->actingAs($administrator)->post(route('comunicados.publish', [$building, $message]), [
            'updated_at' => $message->fresh()->updated_at?->toIso8601String(),
        ])->assertSessionHasErrors(['documentos']);
        $this->assertSame('borrador', $message->fresh()->estado->value);
        $this->actingAs($administrator)->delete(route('comunicados.documents.destroy', [$building, $message, $missing]))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('documentos_comunicado', ['id' => $missing->id]);
        $this->actingAs($administrator)->post(route('comunicados.documents.store', [$building, $message]), [
            'archivo' => $this->pngFile('temporal.png'),
        ])->assertSessionHasNoErrors();
        $temporary = DocumentoComunicadoEloquentModel::query()->whereKeyNot($document->id)->sole();
        $this->actingAs($administrator)->delete(route('comunicados.documents.destroy', [$building, $message, $temporary]))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('documentos_comunicado', ['id' => $temporary->id]);
        Storage::disk('communications')->assertMissing($temporary->ruta_privada);
        $this->actingAs($administrator)->get(route('comunicados.documents.download', [$building, $message, $document]))
            ->assertOk()->assertHeader('content-type', 'image/png');
        $outsider = UserEloquentModel::factory()->create();
        $this->actingAs($outsider)->get(route('comunicados.documents.download', [$building, $message, $document]))->assertNotFound();
        $invalidResponse = $this->actingAs($administrator)->post(route('comunicados.documents.store', [$building, $message]), [
            'archivo' => UploadedFile::fake()->create('malware.exe', 1, 'application/x-msdownload'),
        ]);
        $invalidResponse->assertSessionHasErrors(['archivo']);
        config(['comunicacion.document_disk' => 'public']);
        try {
            app(ComunicacionRepositoryInterface::class)->storeDocument(
                $administrator->id,
                $building->id,
                $message->id,
                $this->pngFile('publico.png'),
                null,
            );
            $this->fail('Comunicación aceptó un disco servido públicamente.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('privado', $exception->getMessage());
        } finally {
            config(['comunicacion.document_disk' => 'communications']);
        }
        auth()->logout();
        $this->get(route('comunicados.documents.download', [$building, $message, $document]))->assertRedirect(route('login'));

        Queue::fake();
        Storage::fake('communications-next');
        config(['comunicacion.document_disk' => 'communications-next']);
        $this->publish($administrator, $building, $message);
        $delivery = EntregaComunicadoEloquentModel::query()->sole();
        Notification::fake();
        (new EnviarEntregaComunicadoJob($delivery->id))->handle(app(EnviarEntregaComunicadoAction::class));
        Notification::assertSentOnDemand(ComunicadoPublicadoNotification::class);
        $this->assertSame('enviada', $delivery->refresh()->estado->value);

        $url = URL::temporarySignedRoute('comunicados.documents.signed', now()->addMinutes(5), [
            'entrega' => $delivery->id,
            'documento' => $document->id,
        ]);
        $this->get($url)->assertOk()->assertHeader('content-type', 'image/png');
        $expiredUrl = URL::temporarySignedRoute('comunicados.documents.signed', now()->subMinute(), [
            'entrega' => $delivery->id,
            'documento' => $document->id,
        ]);
        $this->get($expiredUrl)->assertForbidden();
        $this->get($url.'&alterado=1')->assertForbidden();
        $otherMessage = $this->createMessage($administrator, $building);
        $this->publish($administrator, $building, $otherMessage);
        $otherDelivery = EntregaComunicadoEloquentModel::query()->where('comunicado_id', $otherMessage->id)->sole();
        $mismatchedUrl = URL::temporarySignedRoute('comunicados.documents.signed', now()->addMinutes(5), [
            'entrega' => $otherDelivery->id,
            'documento' => $document->id,
        ]);
        $this->get($mismatchedUrl)->assertNotFound();
        Storage::disk('communications')->put($document->ruta_privada, 'contenido alterado');
        $this->get($url)->assertStatus(409);

        $this->actingAs($administrator)->delete(route('comunicados.documents.destroy', [$building, $message, $document]))->assertSessionHasErrors(['estado']);
    }

    public function test_outbox_dispatch_claim_recovery_failure_and_manual_retry_are_durable(): void
    {
        [$administrator, $building, $department] = $this->fixture();
        $message = $this->createMessage($administrator, $building);
        $this->ownerFor($building, $department, '1710000031', 'cola@example.test');
        Queue::fake();
        $this->publish($administrator, $building, $message);
        $delivery = EntregaComunicadoEloquentModel::query()->sole();
        $repository = app(ComunicacionRepositoryInterface::class);

        Queue::assertPushed(DespacharEntregasComunicadoJob::class, fn (DespacharEntregasComunicadoJob $job): bool => $job->messageId === $message->id);
        $this->assertSame('pendiente', $delivery->estado->value);
        $this->assertTrue($delivery->disponible_at->lessThanOrEqualTo(now()), $delivery->disponible_at->toIso8601String().' > '.now()->toIso8601String());
        $this->assertSame([$delivery->id], $repository->dueDeliveryIds($message->id));
        $this->assertSame(300, (new EnviarEntregaComunicadoJob($delivery->id))->uniqueFor());
        (new DespacharEntregasComunicadoJob($message->id))->handle($repository);
        (new DespacharEntregasComunicadoJob($message->id))->handle($repository);
        Queue::assertPushed(EnviarEntregaComunicadoJob::class, fn (EnviarEntregaComunicadoJob $job): bool => $job->deliveryId === $delivery->id);
        Queue::assertPushedTimes(EnviarEntregaComunicadoJob::class, 1);
        $claim = $repository->claimDelivery($delivery->id);
        $this->assertNotNull($claim);
        $this->assertNull($repository->claimDelivery($delivery->id));
        $repository->markDeliveryFailure($delivery->id, 1, new RuntimeException('Fallo temporal uno'));
        $this->assertSame('reintento', $delivery->refresh()->estado->value);

        Carbon::setTestNow(now()->addSeconds(61));
        $second = $repository->claimDelivery($delivery->id);
        $this->assertSame(2, $second['attempt']);
        $repository->markDeliveryFailure($delivery->id, 2, new RuntimeException('Fallo temporal dos'));
        Carbon::setTestNow(now()->addSeconds(301));
        $third = $repository->claimDelivery($delivery->id);
        $this->assertSame(3, $third['attempt']);
        $repository->markDeliveryFailure($delivery->id, 3, new RuntimeException('Fallo terminal'));
        $this->assertSame('fallida', $delivery->refresh()->estado->value);
        $this->assertSame('RuntimeException', $delivery->error_codigo);

        $this->actingAs($administrator)->post(route('comunicados.deliveries.retry', [$building, $message, $delivery]))->assertSessionHasNoErrors();
        $this->assertSame('reintento', $delivery->refresh()->estado->value);
        $this->assertDatabaseHas('bitacora_comunicacion', ['comunicado_id' => $message->id, 'tipo' => 'entrega_reintentada']);

        $crashMessage = $this->createMessage($administrator, $building);
        $this->publish($administrator, $building, $crashMessage);
        $crashedDelivery = EntregaComunicadoEloquentModel::query()->where('comunicado_id', $crashMessage->id)->sole();
        foreach ([1, 2, 3] as $attempt) {
            $this->assertSame($attempt, $repository->claimDelivery($crashedDelivery->id)['attempt']);
            Carbon::setTestNow(now()->addSeconds(301));
        }
        $this->assertNull($repository->claimDelivery($crashedDelivery->id));
        $this->assertSame('fallida', $crashedDelivery->refresh()->estado->value);
        $this->assertSame(3, $crashedDelivery->intentos);
        $this->assertSame('LeaseExpirado', $crashedDelivery->error_codigo);
    }

    public function test_published_content_documents_history_and_delivery_transitions_are_database_protected(): void
    {
        [$administrator, $building, $department] = $this->fixture();
        $message = $this->createMessage($administrator, $building);
        $empty = $this->createMessage($administrator, $building);
        $this->assertDatabaseRejects(static fn () => DB::table('comunicados')->where('id', $empty->id)->update([
            'estado' => 'publicado', 'version' => 2, 'actualizado_por_user_id' => $administrator->id,
            'publicado_por_user_id' => $administrator->id, 'publicado_at' => now(),
            'total_candidatos' => 1, 'total_entregas' => 1, 'updated_at' => now()->addSecond(),
        ]));
        $this->ownerFor($building, $department, '1710000041', 'integridad@example.test');
        $this->actingAs($administrator)->post(route('comunicados.documents.store', [$building, $message]), [
            'archivo' => $this->pngFile('integridad.png'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $document = DocumentoComunicadoEloquentModel::query()->sole();
        $this->publish($administrator, $building, $message);
        $delivery = EntregaComunicadoEloquentModel::query()->sole();
        $event = BitacoraComunicacionEloquentModel::query()->where('comunicado_id', $message->id)->firstOrFail();

        $this->actingAs($administrator)->put(route('comunicados.update', [$building, $message]), [
            'titulo' => 'Mutación rechazada', 'cuerpo' => 'No permitido', 'audiencias' => ['residentes'],
            'updated_at' => $message->refresh()->updated_at?->toIso8601String(),
        ])->assertSessionHasErrors(['estado']);
        $this->assertDatabaseRejects(static fn () => DB::table('comunicados')->where('id', $message->id)->update(['titulo' => 'Mutación SQL']));
        $this->assertDatabaseRejects(static fn () => DB::table('comunicados')->where('id', $message->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('documentos_comunicado')->where('id', $document->id)->update(['descripcion' => 'Mutación SQL']));
        $this->assertDatabaseRejects(static fn () => DB::table('documentos_comunicado')->where('id', $document->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('bitacora_comunicacion')->where('id', $event->id)->update(['detalle' => '{}']));
        $this->assertDatabaseRejects(static fn () => DB::table('bitacora_comunicacion')->where('id', $event->id)->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('entregas_comunicado')->where('id', $delivery->id)->update(['estado' => 'enviada']));
        $this->assertDatabaseRejects(static fn () => DB::table('entregas_comunicado')->insert([
            'id' => (string) Str::uuid(), 'edificio_id' => $building->id, 'comunicado_id' => $message->id,
            'canal' => 'email', 'destino_normalizado' => 'adicional@example.test', 'estado' => 'pendiente', 'intentos' => 0,
            'disponible_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->actingAs($administrator)->patch(route('comunicados.archive', [$building, $message]))->assertSessionHasNoErrors();
        $this->assertSame('archivado', $message->refresh()->estado->value);
        $this->assertDatabaseHas('bitacora_comunicacion', ['comunicado_id' => $message->id, 'tipo' => 'comunicado_archivado']);
    }

    public function test_rollback_preflight_preserves_communication_when_role_is_assigned(): void
    {
        [$administrator, $building] = $this->fixture();
        $manager = UserEloquentModel::factory()->create();
        $this->addMember($building, $manager, RolEdificio::GESTOR_COMUNICACION, $administrator);
        foreach (['050000_track_comunicacion_document_cleanup', '040000_persist_comunicacion_document_disk', '030000_require_non_empty_comunicacion_publication', '020000_harden_comunicacion_integrity'] as $migrationName) {
            $migration = require base_path("src/Comunicacion/Infrastructure/Migrations/2026_10_15_{$migrationName}.php");
            try {
                $migration->down();
                $this->fail("El rollback {$migrationName} modificó el esquema antes de detectar el rol asignado.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('gestor_comunicacion', $exception->getMessage());
            }
        }
        $this->assertTrue(Schema::hasTable('comunicados'));
        $this->assertTrue(Schema::hasTable('limpieza_documentos_comunicacion'));
        $this->assertTrue(Schema::hasColumn('documentos_comunicado', 'disco'));
    }

    public function test_document_disk_migration_backfills_existing_rows_without_updating_immutable_documents(): void
    {
        [$administrator, $building] = $this->fixture();
        $message = $this->createMessage($administrator, $building);
        $this->actingAs($administrator)->post(route('comunicados.documents.store', [$building, $message]), [
            'archivo' => $this->pngFile('legado.png'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $documentId = DocumentoComunicadoEloquentModel::query()->sole()->id;

        DB::statement('DROP TRIGGER documentos_comunicado_disco_insert_guard');
        Schema::table('documentos_comunicado', static function (Blueprint $blueprint): void {
            $blueprint->dropColumn('disco');
        });
        config(['comunicacion.document_legacy_disk' => 'communications']);
        $migration = require base_path('src/Comunicacion/Infrastructure/Migrations/2026_10_15_040000_persist_comunicacion_document_disk.php');
        $migration->up();

        $this->assertSame('communications', DB::table('documentos_comunicado')->where('id', $documentId)->value('disco'));
    }

    public function test_non_empty_publication_migration_is_reversible_on_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $migration = require base_path('src/Comunicacion/Infrastructure/Migrations/2026_10_15_030000_require_non_empty_comunicacion_publication.php');
        $migration->down();
        $weaker = (string) DB::table('sqlite_master')->where('type', 'trigger')
            ->where('name', 'comunicados_shape_update_guard')->value('sql');
        $this->assertStringNotContainsString('NEW.total_candidatos < 1', $weaker);
        $this->assertStringNotContainsString('NEW.total_entregas < 1', $weaker);

        $migration->up();
        $strict = (string) DB::table('sqlite_master')->where('type', 'trigger')
            ->where('name', 'comunicados_shape_update_guard')->value('sql');
        $this->assertStringContainsString('NEW.total_candidatos < 1', $strict);
        $this->assertStringContainsString('NEW.total_entregas < 1', $strict);
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel, DepartamentoEloquentModel} */
    private function fixture(): array
    {
        $administrator = UserEloquentModel::factory()->create();
        $building = $this->buildingFor($administrator);

        return [$administrator, $building, $this->departmentFor($building, 'A-101')];
    }

    private function buildingFor(UserEloquentModel $administrator): EdificioEloquentModel
    {
        $number = DB::table('edificios')->count() + 1;
        $created = app(CreateEdificioAction::class)->execute([
            'nombre' => 'Edificio Comunicación '.$number,
            'ruc' => str_pad((string) $number, 13, '0', STR_PAD_LEFT),
            'direccion' => 'Dirección '.$number,
            'ciudad' => 'Quito',
            'timezone' => 'America/Guayaquil',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $administrator->id);

        return EdificioEloquentModel::query()->findOrFail($created->id());
    }

    private function departmentFor(EdificioEloquentModel $building, string $code, string $status = 'activo'): DepartamentoEloquentModel
    {
        $tower = $building->torres()->sole();
        $floor = PisoEloquentModel::query()->where('edificio_id', $building->id)->first() ?? PisoEloquentModel::query()->forceCreate([
            'edificio_id' => $building->id, 'torre_id' => $tower->id, 'numero' => '1', 'nombre' => 'Primer piso', 'orden' => 1, 'estado' => 'activo',
        ]);

        return DepartamentoEloquentModel::query()->forceCreate([
            'edificio_id' => $building->id, 'piso_id' => $floor->id, 'codigo' => $code,
            'nombre' => 'Departamento '.$code, 'alicuota' => '5.000000', 'estado' => $status,
        ]);
    }

    private function ownerFor(EdificioEloquentModel $building, DepartamentoEloquentModel $department, string $identification, ?string $email): PropietarioEloquentModel
    {
        $owner = PropietarioEloquentModel::query()->forceCreate([
            'tipo_persona' => 'persona_natural', 'nombres' => 'Propietario', 'apellidos' => $identification,
            'razon_social' => null, 'tipo_identificacion' => 'cedula', 'identificacion' => $identification,
            'telefono' => null, 'celular' => null, 'correo' => $email, 'direccion' => 'Quito',
            'estado' => 'activo', 'observaciones' => null,
        ]);
        $owner->edificios()->attach($building->id);
        DepartamentoPropietarioEloquentModel::query()->forceCreate([
            'edificio_id' => $building->id, 'departamento_id' => $department->id, 'propietario_id' => $owner->id,
            'nombre_propietario' => 'Propietario '.$identification, 'tipo_identificacion_snapshot' => 'cedula',
            'identificacion_snapshot' => $identification, 'porcentaje' => '100.000000', 'fecha_inicio' => '2026-01-01',
            'fecha_fin' => null, 'estado' => 'activa', 'observaciones' => null,
        ]);

        return $owner;
    }

    private function residentFor(EdificioEloquentModel $building, DepartamentoEloquentModel $department, ?TerceroEloquentModel $thirdParty, string $identification, ?string $email = null, string $startDate = '2026-01-01'): ResidenteEloquentModel
    {
        $thirdParty ??= TerceroEloquentModel::query()->forceCreate([
            'tipo_persona' => 'persona_natural', 'nombres' => 'Residente', 'apellidos' => $identification,
            'razon_social' => null, 'tipo_identificacion' => 'cedula', 'identificacion' => $identification,
            'telefono' => null, 'celular' => null, 'correo' => $email, 'direccion' => 'Quito',
        ]);
        $resident = ResidenteEloquentModel::query()->forceCreate(['tercero_id' => $thirdParty->id, 'estado' => 'activo', 'observaciones' => null]);
        $resident->edificios()->attach($building->id);
        DepartamentoResidenteEloquentModel::query()->forceCreate([
            'edificio_id' => $building->id, 'departamento_id' => $department->id, 'residente_id' => $resident->id,
            'nombre_residente' => 'Residente '.$identification, 'tipo_identificacion_snapshot' => 'cedula',
            'identificacion_snapshot' => $identification, 'tipo_ocupacion' => 'arrendatario',
            'fecha_inicio' => $startDate, 'fecha_fin' => null, 'estado' => 'activa', 'observaciones' => null,
        ]);

        return $resident;
    }

    private function createMessage(UserEloquentModel $actor, EdificioEloquentModel $building, array $overrides = []): ComunicadoEloquentModel
    {
        $existingIds = ComunicadoEloquentModel::query()->pluck('id');
        $data = array_merge([
            'titulo' => 'Mantenimiento preventivo',
            'cuerpo' => 'El servicio se suspenderá durante dos horas.',
            'audiencias' => ['propietarios'],
        ], $overrides);
        $this->actingAs($actor)->post(route('comunicados.store', $building), $data)->assertSessionHasNoErrors();

        return ComunicadoEloquentModel::query()->whereNotIn('id', $existingIds)->sole();
    }

    private function publish(UserEloquentModel $actor, EdificioEloquentModel $building, ComunicadoEloquentModel $message): void
    {
        $this->actingAs($actor)->post(route('comunicados.publish', [$building, $message]), [
            'updated_at' => $message->fresh()->updated_at?->toIso8601String(),
        ])->assertSessionHasNoErrors();
    }

    private function addMember(EdificioEloquentModel $building, UserEloquentModel $member, RolEdificio $role, UserEloquentModel $actor): void
    {
        $building->usuarios()->attach($member->id, ['creado_por_user_id' => $actor->id]);
        DB::table('edificio_usuario_roles')->insert([
            'edificio_id' => $building->id, 'user_id' => $member->id, 'rol_codigo' => $role->value,
            'asignado_por_user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pngFile(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngContents());
    }

    private function pngContents(): string
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl2QAAAAASUVORK5CYII=', true);
        $this->assertIsString($contents);

        return $contents;
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('La base permitió una mutación protegida de Comunicación.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
