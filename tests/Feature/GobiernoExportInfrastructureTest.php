<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gobierno\Infrastructure\Jobs\GenerarExportacionAuditoriaJob;
use Tests\TestCase;

final class GobiernoExportInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_permission_is_scoped_by_building_and_not_granted_to_consulta(): void
    {
        [$firstAdministrator, $firstBuilding] = $this->fixture();
        [$secondAdministrator, $secondBuilding] = $this->fixture();
        $exporter = UserEloquentModel::factory()->create();
        $this->addMember($firstBuilding, $exporter, RolEdificio::GESTOR_GOBIERNO, $firstAdministrator);

        $access = app(AccesoEdificioRepositoryInterface::class);
        $this->assertTrue($access->hasPermission($firstAdministrator->id, $firstBuilding->id, PermisoEdificio::GOBIERNO_EXPORTAR));
        $this->assertTrue($access->hasPermission($exporter->id, $firstBuilding->id, PermisoEdificio::GOBIERNO_EXPORTAR));
        $this->assertFalse($access->hasPermission($exporter->id, $secondBuilding->id, PermisoEdificio::GOBIERNO_EXPORTAR));
        $this->assertFalse(DB::table('rol_permisos')->where([
            'rol_codigo' => RolEdificio::CONSULTA->value,
            'permiso_codigo' => PermisoEdificio::GOBIERNO_EXPORTAR->value,
        ])->exists());
        $this->assertTrue(DB::table('rol_permisos')->where([
            'rol_codigo' => RolEdificio::ADMINISTRADOR->value,
            'permiso_codigo' => PermisoEdificio::GOBIERNO_EXPORTAR->value,
        ])->exists());
        $this->assertTrue(DB::table('rol_permisos')->where([
            'rol_codigo' => RolEdificio::GESTOR_GOBIERNO->value,
            'permiso_codigo' => PermisoEdificio::GOBIERNO_EXPORTAR->value,
        ])->exists());

        $this->assertNotSame($firstAdministrator->id, $secondAdministrator->id);
    }

    public function test_export_storage_queue_limits_and_tables_are_private_and_bounded(): void
    {
        $this->assertTrue(Schema::hasTable('exportaciones_auditoria'));
        $this->assertTrue(Schema::hasTable('exportacion_auditoria_edificios'));
        $this->assertTrue(Schema::hasTable('bitacora_exportacion_auditoria'));
        $this->assertTrue(Schema::hasTable('limpieza_archivos_exportacion'));
        $this->assertSame('private', config('filesystems.disks.audit-exports.visibility'));
        $this->assertFalse(config('filesystems.disks.audit-exports.serve'));
        $this->assertTrue(config('filesystems.disks.audit-exports.throw'));
        $this->assertSame(900, config('queue.connections.database-exports.retry_after'));
        $this->assertSame(24, config('gobierno.exports.ttl_hours'));
        $this->assertSame(90, config('gobierno.exports.max_range_days'));
        $this->assertSame(50000, config('gobierno.exports.csv_max_rows'));
        $this->assertSame(2000, config('gobierno.exports.pdf_max_rows'));
        $jobTimeout = (new GenerarExportacionAuditoriaJob(self::uuid()))->timeout;
        $this->assertGreaterThan($jobTimeout, config('gobierno.exports.lease_seconds'));
        $this->assertGreaterThan($jobTimeout, config('queue.connections.database-exports.retry_after'));
        $this->assertGreaterThan($jobTimeout, config('gobierno.exports.temp_max_age_seconds'));
        $this->assertGreaterThan($jobTimeout, config('gobierno.exports.orphan_grace_seconds'));
        $this->assertSame(500, config('gobierno.exports.recent_scan_limit'));
    }

    public function test_export_schema_rejects_invalid_ranges_and_keeps_audit_history_immutable(): void
    {
        [$administrator, $building] = $this->fixture();
        $this->expectDatabaseConstraint(static function () use ($administrator): void {
            DB::table('exportaciones_auditoria')->insert([
                ...self::validExport($administrator->id),
                'desde_utc' => '2026-10-22 12:00:00',
                'hasta_utc' => '2026-10-21 12:00:00',
                'corte_at' => '2026-10-21 12:00:00',
            ]);
        });
        $this->expectDatabaseConstraint(static function () use ($administrator): void {
            DB::table('exportaciones_auditoria')->insert([
                ...self::validExport($administrator->id),
                'desde_utc' => '2026-10-20 00:00:00',
                'hasta_utc' => '2026-10-21 00:00:00',
                'corte_at' => '2026-10-20 00:00:00',
            ]);
        });

        $exportId = self::uuid();
        DB::table('exportaciones_auditoria')->insert([
            ...self::validExport($administrator->id),
            'id' => $exportId,
        ]);
        DB::table('exportacion_auditoria_edificios')->insert([
            'exportacion_id' => $exportId,
            'edificio_id' => $building->id,
            'nombre_snapshot' => $building->nombre,
            'timezone_snapshot' => $building->timezone,
            'permisos_fuente' => json_encode(['miembros.ver'], JSON_THROW_ON_ERROR),
            'detalle_incluido' => true,
            'created_at' => now(),
        ]);
        $this->expectDatabaseConstraint(static fn () => DB::table('exportaciones_auditoria')
            ->where('id', $exportId)->update(['desde_utc' => '2026-10-22 13:00:00']));
        $this->expectDatabaseConstraint(static fn () => DB::table('exportacion_auditoria_edificios')
            ->where('exportacion_id', $exportId)->update(['nombre_snapshot' => 'Nombre alterado']));
        $this->expectDatabaseConstraint(static fn () => DB::table('bitacora_exportacion_auditoria')->insert([
            'id' => self::uuid(),
            'exportacion_id' => $exportId,
            'tipo' => 'procesamiento_iniciado',
            'actor_tipo' => 'sistema',
            'actor_user_id' => $administrator->id,
            'intento' => 1,
            'error_codigo' => null,
            'created_at' => now(),
        ]));
        $eventId = self::uuid();
        DB::table('bitacora_exportacion_auditoria')->insert([
            'id' => $eventId,
            'exportacion_id' => $exportId,
            'tipo' => 'solicitada',
            'actor_tipo' => 'usuario',
            'actor_user_id' => $administrator->id,
            'intento' => null,
            'error_codigo' => null,
            'created_at' => now(),
        ]);
        $this->expectDatabaseConstraint(static fn () => DB::table('exportacion_auditoria_edificios')->insert([
            'exportacion_id' => $exportId,
            'edificio_id' => $building->id,
            'nombre_snapshot' => $building->nombre,
            'timezone_snapshot' => $building->timezone,
            'permisos_fuente' => '[]',
            'detalle_incluido' => false,
            'created_at' => now(),
        ]));

        $this->expectDatabaseConstraint(static fn () => DB::table('bitacora_exportacion_auditoria')
            ->where('id', $eventId)->update(['tipo' => 'fallida']));
        $this->expectDatabaseConstraint(static fn () => DB::table('bitacora_exportacion_auditoria')
            ->where('id', $eventId)->delete());
        $this->assertDatabaseHas('bitacora_exportacion_auditoria', ['id' => $eventId, 'tipo' => 'solicitada']);
    }

    public function test_export_migration_refuses_rollback_while_history_exists(): void
    {
        [$administrator] = $this->fixture();
        DB::table('exportaciones_auditoria')->insert(self::validExport($administrator->id));
        $migration = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_010000_create_gobierno_export_tables.php');

        try {
            $migration->down();
            $this->fail('El rollback retiro la infraestructura con historial de exportaciones.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('historial', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('exportaciones_auditoria'));
    }

    public function test_export_migrations_roll_back_in_reverse_order_without_history(): void
    {
        $hardening = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_020000_harden_gobierno_export_integrity.php');
        $tables = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_010000_create_gobierno_export_tables.php');
        $permission = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_000000_add_gobierno_export_permission.php');

        $hardening->down();
        $tables->down();
        $permission->down();

        $this->assertFalse(Schema::hasTable('exportaciones_auditoria'));
        $this->assertDatabaseMissing('permisos', ['codigo' => PermisoEdificio::GOBIERNO_EXPORTAR->value]);
    }

    public function test_export_rollback_preflight_leaves_infrastructure_intact_when_permission_was_reassigned(): void
    {
        DB::table('rol_permisos')->insert([
            'rol_codigo' => RolEdificio::CONSULTA->value,
            'permiso_codigo' => PermisoEdificio::GOBIERNO_EXPORTAR->value,
        ]);
        $hardening = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_21_020000_harden_gobierno_export_integrity.php');

        try {
            $hardening->down();
            $this->fail('El rollback modificó la infraestructura pese al permiso reasignado.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('otros roles', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('exportaciones_auditoria'));
        $this->assertDatabaseHas('permisos', ['codigo' => PermisoEdificio::GOBIERNO_EXPORTAR->value]);
        $this->assertSame(1, DB::table('sqlite_master')->where([
            'type' => 'trigger',
            'name' => 'exportaciones_auditoria_update_guard',
        ])->count());
    }

    /** @return array{UserEloquentModel, EdificioEloquentModel} */
    private function fixture(): array
    {
        $administrator = UserEloquentModel::factory()->create();
        $number = DB::table('edificios')->count() + 1;
        $created = app(CreateEdificioAction::class)->execute([
            'nombre' => 'Edificio Exportacion '.$number,
            'ruc' => str_pad((string) $number, 13, '0', STR_PAD_LEFT),
            'direccion' => 'Direccion '.$number,
            'ciudad' => 'Quito',
            'timezone' => 'America/Guayaquil',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
        ], $administrator->id);

        return [$administrator, EdificioEloquentModel::query()->findOrFail($created->id())];
    }

    private function addMember(EdificioEloquentModel $building, UserEloquentModel $member, RolEdificio $role, UserEloquentModel $actor): void
    {
        $building->usuarios()->attach($member->id, ['creado_por_user_id' => $actor->id]);
        DB::table('edificio_usuario_roles')->insert([
            'edificio_id' => $building->id,
            'user_id' => $member->id,
            'rol_codigo' => $role->value,
            'asignado_por_user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function validExport(string $userId): array
    {
        return [
            'id' => self::uuid(),
            'solicitante_user_id' => $userId,
            'idempotency_key' => self::uuid(),
            'formato' => 'csv',
            'estado' => 'pendiente',
            'fuente' => null,
            'tipo' => null,
            'desde_utc' => '2026-10-20 00:00:00',
            'hasta_utc' => '2026-10-21 00:00:00',
            'corte_at' => '2026-10-20 12:00:00',
            'intentos' => 0,
            'lease_token' => null,
            'lease_until' => null,
            'disponible_at' => '2026-10-20 12:00:00',
            'total_eventos' => null,
            'eventos_procesados' => 0,
            'disco' => null,
            'ruta_privada' => null,
            'nombre_archivo' => null,
            'mime_type' => null,
            'tamano_bytes' => null,
            'sha256' => null,
            'error_codigo' => null,
            'iniciada_at' => null,
            'completada_at' => null,
            'fallida_at' => null,
            'expires_at' => null,
            'archivo_eliminado_at' => null,
            'created_at' => '2026-10-20 12:00:00',
            'updated_at' => '2026-10-20 12:00:00',
        ];
    }

    private static function uuid(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    private function expectDatabaseConstraint(callable $operation): void
    {
        try {
            $operation();
            $this->fail('La base de datos acepto una operacion invalida.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
