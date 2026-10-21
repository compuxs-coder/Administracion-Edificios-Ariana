<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gobierno\Domain\Contracts\AuditoriaRepositoryInterface;
use Tests\TestCase;

final class GobiernoAuditoriaWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_government_role_is_explicit_and_multi_building_selection_requires_permission_on_every_building(): void
    {
        [$firstAdministrator, $firstBuilding] = $this->fixture();
        [$secondAdministrator, $secondBuilding] = $this->fixture();
        $auditor = UserEloquentModel::factory()->create();
        $viewer = UserEloquentModel::factory()->create();
        $operationsManager = UserEloquentModel::factory()->create();
        $this->addMember($firstBuilding, $auditor, RolEdificio::GESTOR_GOBIERNO, $firstAdministrator);
        $this->addMember($firstBuilding, $viewer, RolEdificio::CONSULTA, $firstAdministrator);
        $this->addMember($firstBuilding, $operationsManager, RolEdificio::GESTOR_OPERACIONES, $firstAdministrator);

        $this->get(route('gobierno.auditoria.index'))->assertRedirect(route('login'));
        $this->actingAs($viewer)->get(route('gobierno.auditoria.index'))->assertForbidden();
        $this->actingAs($operationsManager)->get(route('gobierno.auditoria.index'))->assertForbidden();
        $this->actingAs($auditor)->get(route('gobierno.auditoria.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Gobierno/Auditoria/index')
                ->has('edificios', 1)
                ->where('edificios.0.id', $firstBuilding->id));

        $this->actingAs($auditor)->get(route('gobierno.auditoria.index', [
            'edificios' => [$firstBuilding->id, $secondBuilding->id],
        ]))->assertForbidden();

        $this->addMember($secondBuilding, $auditor, RolEdificio::GESTOR_GOBIERNO, $secondAdministrator);
        $this->actingAs($auditor)->get(route('gobierno.auditoria.index', [
            'edificios' => [$firstBuilding->id, $secondBuilding->id],
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('edificios', 2)
            ->has('filters.edificios', 2));

        $access = app(AccesoEdificioRepositoryInterface::class);
        $this->assertFalse($access->hasPermission($viewer->id, $firstBuilding->id, PermisoEdificio::GOBIERNO_VER));
        foreach ([
            PermisoEdificio::GOBIERNO_VER,
            PermisoEdificio::GOBIERNO_DETALLES_VER,
            PermisoEdificio::MIEMBROS_VER,
            PermisoEdificio::OPERACIONES_VER,
            PermisoEdificio::MANTENIMIENTO_PREVENTIVO_VER,
            PermisoEdificio::AREAS_COMUNES_VER,
            PermisoEdificio::RESERVAS_AREAS_COMUNES_VER,
            PermisoEdificio::COMUNICADOS_VER,
        ] as $permission) {
            $this->assertTrue($access->hasPermission($auditor->id, $firstBuilding->id, $permission));
        }

        $this->actingAs($auditor)->post(route('gobierno.auditoria.index'))->assertMethodNotAllowed();
    }

    public function test_each_native_adapter_is_paginated_and_never_exposes_raw_history_or_sensitive_values(): void
    {
        [$administrator, $building] = $this->fixture();
        $this->createNativeEvents($administrator, $building);
        config([
            'gobierno.audit_page_size' => 2,
            'gobierno.retention_days' => ['comunicacion.comunicado_creado' => 365],
        ]);
        $repository = app(AuditoriaRepositoryInterface::class);

        $firstPage = $repository->paginateForUser($administrator->id, ['edificios' => [$building->id], 'page' => 1]);
        $secondPage = $repository->paginateForUser($administrator->id, ['edificios' => [$building->id], 'page' => 2]);
        $thirdPage = $repository->paginateForUser($administrator->id, ['edificios' => [$building->id], 'page' => 3]);
        $this->assertGreaterThanOrEqual(5, $firstPage['total']);
        $this->assertCount(2, $firstPage['items']);
        $this->assertCount(2, $secondPage['items']);
        $this->assertSame([], array_intersect(
            array_column($firstPage['items'], 'id'),
            array_column($secondPage['items'], 'id'),
        ));
        $this->assertCount($firstPage['total'], [...$firstPage['items'], ...$secondPage['items'], ...$thirdPage['items']]);

        $allEvents = [...$firstPage['items'], ...$secondPage['items'], ...$thirdPage['items']];
        $timestamps = array_column($allEvents, 'ocurridoAt');
        $sortedTimestamps = $timestamps;
        rsort($sortedTimestamps, SORT_STRING);
        $this->assertSame($sortedTimestamps, $timestamps);
        foreach ($timestamps as $timestamp) {
            $this->assertStringEndsWith('Z', $timestamp);
        }
        $this->assertSame('comunicacion', $allEvents[0]['fuente']);
        $this->assertSame('areas_comunes', $allEvents[1]['fuente']);
        $this->assertEqualsCanonicalizing(
            ['accesos', 'operaciones', 'mantenimiento', 'areas_comunes', 'comunicacion'],
            array_values(array_unique(array_column($allEvents, 'fuente'))),
        );
        foreach (['accesos', 'operaciones', 'mantenimiento', 'areas_comunes', 'comunicacion'] as $source) {
            $filtered = $repository->paginateForUser($administrator->id, [
                'edificios' => [$building->id], 'fuente' => $source,
            ]);
            $this->assertNotEmpty($filtered['items']);
            $this->assertSame([$source], array_values(array_unique(array_column($filtered['items'], 'fuente'))));
        }
        $typeFiltered = $repository->paginateForUser($administrator->id, [
            'edificios' => [$building->id], 'fuente' => 'comunicacion', 'tipo' => 'comunicado_creado',
        ]);
        $this->assertSame(1, $typeFiltered['total']);
        $this->assertSame('comunicado_creado', $typeFiltered['items'][0]['tipo']);
        $this->actingAs($administrator)->get(route('gobierno.auditoria.index', [
            'tipo' => 'evento_inexistente',
        ]))->assertSessionHasErrors(['tipo']);

        $encoded = json_encode($allEvents, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('persona.sensible@example.test', $encoded);
        $this->assertStringNotContainsString('1719999999', $encoded);
        $this->assertStringNotContainsString('Contenido privado del comunicado', $encoded);
        $this->assertStringNotContainsString('TOKEN-PRIVADO-XYZ', $encoded);
        $this->assertStringNotContainsString('clave-no-exponer', $encoded);
        foreach (['eventos_acceso_edificio', 'bitacora_orden_operativa', 'bitacora_plan_mantenimiento', 'bitacora_areas_comunes', 'bitacora_comunicacion'] as $table) {
            foreach (DB::table($table)->pluck('id') as $rawEventId) {
                $this->assertStringNotContainsString((string) $rawEventId, $encoded);
            }
        }
        $communicationEvent = collect($allEvents)->firstWhere('tipo', 'comunicado_creado');
        $this->assertSame(365, $communicationEvent['retencionDias']);
        $this->assertFalse($communicationEvent['detalleRestringido']);
        $this->assertSame(12, strlen($communicationEvent['id']));
    }

    public function test_government_permission_without_source_permissions_returns_no_native_events(): void
    {
        [$administrator, $building] = $this->fixture();
        $limitedAuditor = UserEloquentModel::factory()->create();
        DB::table('roles')->insert([
            'codigo' => 'auditor_limitado', 'nombre' => 'Auditor limitado',
            'descripcion' => 'Acceso al modulo sin permisos sobre fuentes.', 'es_sistema' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rol_permisos')->insert([
            'rol_codigo' => 'auditor_limitado', 'permiso_codigo' => PermisoEdificio::GOBIERNO_VER->value,
        ]);
        $this->addMember($building, $limitedAuditor, 'auditor_limitado', $administrator);

        $this->actingAs($limitedAuditor)->get(route('gobierno.auditoria.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('edificios', 1)
                ->has('fuentes', 0)
                ->where('eventos.meta.total', 0)
                ->has('eventos.data', 0));
    }

    public function test_actor_and_resource_references_require_detail_permission_for_the_exact_building(): void
    {
        [$administrator, $building] = $this->fixture();
        $basicAuditor = UserEloquentModel::factory()->create();
        DB::table('roles')->insert([
            'codigo' => 'auditor_basico', 'nombre' => 'Auditor basico',
            'descripcion' => 'Consulta accesos sin identidades protegidas.', 'es_sistema' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([PermisoEdificio::GOBIERNO_VER, PermisoEdificio::MIEMBROS_VER] as $permission) {
            DB::table('rol_permisos')->insert([
                'rol_codigo' => 'auditor_basico', 'permiso_codigo' => $permission->value,
            ]);
        }
        $this->addMember($building, $basicAuditor, 'auditor_basico', $administrator);

        $page = app(AuditoriaRepositoryInterface::class)->paginateForUser($basicAuditor->id, [
            'edificios' => [$building->id],
        ]);

        $this->assertSame(1, $page['total']);
        $this->assertNull($page['items'][0]['actor']);
        $this->assertNull($page['items'][0]['recursoReferencia']);
        $this->assertTrue($page['items'][0]['detalleRestringido']);

        $secondBuilding = $this->buildingFor($administrator);
        $detailedPage = app(AuditoriaRepositoryInterface::class)->paginateForUser($administrator->id, [
            'edificios' => [$building->id, $secondBuilding->id],
            'fuente' => 'accesos',
            'tipo' => 'membresia_creada',
        ]);
        $resourceReferences = array_column($detailedPage['items'], 'recursoReferencia');
        $this->assertCount(2, $resourceReferences);
        $this->assertCount(2, array_unique($resourceReferences));
    }

    public function test_reservation_read_permission_exposes_only_the_common_area_source_capability(): void
    {
        [$administrator, $building] = $this->fixture();
        $reservationAuditor = UserEloquentModel::factory()->create();
        DB::table('roles')->insert([
            'codigo' => 'auditor_reservas', 'nombre' => 'Auditor de reservas',
            'descripcion' => 'Consulta Gobierno y eventos de reservas.', 'es_sistema' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([PermisoEdificio::GOBIERNO_VER, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER] as $permission) {
            DB::table('rol_permisos')->insert([
                'rol_codigo' => 'auditor_reservas', 'permiso_codigo' => $permission->value,
            ]);
        }
        $this->addMember($building, $reservationAuditor, 'auditor_reservas', $administrator);

        $options = app(AuditoriaRepositoryInterface::class)->options($reservationAuditor->id);
        $page = app(AuditoriaRepositoryInterface::class)->paginateForUser($reservationAuditor->id, [
            'edificios' => [$building->id],
        ]);

        $this->assertSame(['areas_comunes'], array_column($options['fuentes'], 'codigo'));
        $this->assertSame([
            'reserva_creada', 'reserva_aprobada', 'reserva_rechazada', 'reserva_cancelada',
        ], array_column($options['fuentes'][0]['tipos'], 'codigo'));
        $this->assertSame(0, $page['total']);
    }

    public function test_source_options_follow_the_selected_buildings_permissions(): void
    {
        [$firstAdministrator, $firstBuilding] = $this->fixture();
        [$secondAdministrator, $secondBuilding] = $this->fixture();
        $auditor = UserEloquentModel::factory()->create();
        foreach ([
            ['auditor_operaciones', PermisoEdificio::OPERACIONES_VER],
            ['auditor_comunicacion', PermisoEdificio::COMUNICADOS_VER],
        ] as [$role, $sourcePermission]) {
            DB::table('roles')->insert([
                'codigo' => $role, 'nombre' => $role, 'descripcion' => 'Auditoria limitada por fuente.', 'es_sistema' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([PermisoEdificio::GOBIERNO_VER, $sourcePermission] as $permission) {
                DB::table('rol_permisos')->insert([
                    'rol_codigo' => $role, 'permiso_codigo' => $permission->value,
                ]);
            }
        }
        $this->addMember($firstBuilding, $auditor, 'auditor_operaciones', $firstAdministrator);
        $this->addMember($secondBuilding, $auditor, 'auditor_comunicacion', $secondAdministrator);

        $this->actingAs($auditor)->get(route('gobierno.auditoria.index', [
            'edificios' => [$firstBuilding->id],
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('fuentes', 1)
            ->where('fuentes.0.codigo', 'operaciones'));
        $this->actingAs($auditor)->get(route('gobierno.auditoria.index', [
            'edificios' => [$secondBuilding->id],
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('fuentes', 1)
            ->where('fuentes.0.codigo', 'comunicacion'));
    }

    public function test_role_migration_refuses_rollback_before_removing_an_assigned_auditor(): void
    {
        [$administrator, $building] = $this->fixture();
        $auditor = UserEloquentModel::factory()->create();
        $this->addMember($building, $auditor, RolEdificio::GESTOR_GOBIERNO, $administrator);
        $migration = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_20_000000_add_gobierno_role_and_permissions.php');

        try {
            $migration->down();
            $this->fail('El rollback retiro un rol de Gobierno que estaba asignado.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('gestor_gobierno', $exception->getMessage());
        }

        $this->assertDatabaseHas('roles', ['codigo' => 'gestor_gobierno']);
        $this->assertDatabaseHas('permisos', ['codigo' => 'gobierno.ver']);
        $this->assertDatabaseHas('permisos', ['codigo' => 'gobierno.detalles.ver']);
    }

    public function test_role_migration_refuses_rollback_before_removing_government_permissions_from_another_role(): void
    {
        $this->fixture();
        DB::table('roles')->insert([
            'codigo' => 'auditor_personalizado', 'nombre' => 'Auditor personalizado',
            'descripcion' => 'Rol con concesion posterior.', 'es_sistema' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rol_permisos')->insert([
            'rol_codigo' => 'auditor_personalizado', 'permiso_codigo' => PermisoEdificio::GOBIERNO_VER->value,
        ]);
        $migration = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_20_000000_add_gobierno_role_and_permissions.php');

        try {
            $migration->down();
            $this->fail('El rollback retiro un permiso de Gobierno concedido a otro rol.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('otros roles', $exception->getMessage());
        }

        $this->assertDatabaseHas('rol_permisos', [
            'rol_codigo' => 'auditor_personalizado', 'permiso_codigo' => PermisoEdificio::GOBIERNO_VER->value,
        ]);
        $this->assertDatabaseHas('permisos', ['codigo' => 'gobierno.ver']);
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel} */
    private function fixture(): array
    {
        $administrator = UserEloquentModel::factory()->create();

        return [$administrator, $this->buildingFor($administrator)];
    }

    private function buildingFor(UserEloquentModel $administrator): EdificioEloquentModel
    {
        $number = DB::table('edificios')->count() + 1;
        $created = app(CreateEdificioAction::class)->execute([
            'nombre' => 'Edificio Gobierno '.$number,
            'ruc' => str_pad((string) $number, 13, '0', STR_PAD_LEFT),
            'direccion' => 'Direccion '.$number,
            'ciudad' => 'Quito',
            'timezone' => 'America/Guayaquil',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $administrator->id);

        return EdificioEloquentModel::query()->findOrFail($created->id());
    }

    private function createNativeEvents(UserEloquentModel $actor, EdificioEloquentModel $building): void
    {
        Carbon::setTestNow('2026-10-20 12:01:00 UTC');
        $this->actingAs($actor)->post(route('operaciones.store', $building), [
            'tipo' => 'incidencia', 'titulo' => 'Incidencia privada',
            'descripcion' => 'Reporte de persona.sensible@example.test con identificacion 1719999999 y TOKEN-PRIVADO-XYZ.',
            'prioridad' => 'alta', 'fecha_objetivo' => null, 'torre_id' => null, 'piso_id' => null,
            'departamento_id' => null, 'parqueadero_id' => null, 'bodega_id' => null,
            'ubicacion_detalle' => 'Ubicacion interna', 'reportante_residente_id' => null,
            'proveedor_id' => null, 'contrato_id' => null,
        ])->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-10-20 12:02:00 UTC');
        $this->actingAs($actor)->post(route('mantenimiento-preventivo.store', $building), [
            'codigo' => 'PLAN-GOB-01', 'titulo' => 'Plan de auditoria',
            'descripcion' => 'Descripcion operacional privada.', 'prioridad' => 'media',
            'unidad_recurrencia' => 'mensual', 'intervalo_recurrencia' => 1, 'dias_anticipacion' => 2,
            'torre_id' => null, 'piso_id' => null, 'departamento_id' => null,
            'parqueadero_id' => null, 'bodega_id' => null, 'ubicacion_detalle' => 'Cuarto tecnico',
            'proveedor_id' => null, 'contrato_id' => null,
        ])->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-10-20 12:03:00 UTC');
        $this->actingAs($actor)->post(route('areas-comunes.store', $building), [
            'codigo' => 'AUDITORIO-GOB', 'nombre' => 'Auditorio',
            'descripcion' => 'Uso administrativo restringido.', 'capacidad' => 20,
            'torre_id' => null, 'piso_id' => null, 'ubicacion_detalle' => 'Planta baja',
        ])->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-10-20 12:04:00 UTC');
        $this->actingAs($actor)->post(route('comunicados.store', $building), [
            'titulo' => 'Comunicado reservado',
            'cuerpo' => 'Contenido privado del comunicado para persona.sensible@example.test: clave-no-exponer.',
            'audiencias' => ['propietarios'],
        ])->assertSessionHasNoErrors();
    }

    private function addMember(EdificioEloquentModel $building, UserEloquentModel $member, RolEdificio|string $role, UserEloquentModel $actor): void
    {
        $building->usuarios()->attach($member->id, ['creado_por_user_id' => $actor->id]);
        DB::table('edificio_usuario_roles')->insert([
            'edificio_id' => $building->id,
            'user_id' => $member->id,
            'rol_codigo' => $role instanceof RolEdificio ? $role->value : $role,
            'asignado_por_user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
