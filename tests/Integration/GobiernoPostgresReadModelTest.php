<?php

namespace Tests\Integration;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Gobierno\Domain\Contracts\AuditoriaRepositoryInterface;
use Tests\TestCase;

final class GobiernoPostgresReadModelTest extends TestCase
{
    public function test_unified_native_read_model_runs_on_local_postgresql_without_persisting_fixture_data(): void
    {
        if (env('GOBIERNO_PG_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requiere GOBIERNO_PG_INTEGRATION=1 y PostgreSQL local de desarrollo.');
        }
        $connection = (array) config('database.connections.pgsql');
        if (config('database.default') !== 'pgsql'
            || ! in_array((string) ($connection['host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true)
            || (bool) DB::scalar('SELECT pg_is_in_recovery()')) {
            throw new RuntimeException('La integracion de Gobierno solo admite PostgreSQL local primario.');
        }

        DB::beginTransaction();
        try {
            $this->withoutMiddleware(ValidateCsrfToken::class);
            if (! DB::table($this->table('permisos'))->where('codigo', 'gobierno.ver')->exists()) {
                $migration = require base_path('src/Gobierno/Infrastructure/Migrations/2026_10_20_000000_add_gobierno_role_and_permissions.php');
                $migration->up();
            }
            $administrator = UserEloquentModel::factory()->create();
            $suffix = (string) random_int(1000000000, 1999999999);
            $building = app(CreateEdificioAction::class)->execute([
                'nombre' => 'Integracion Gobierno '.$suffix,
                'ruc' => str_pad($suffix, 13, '0', STR_PAD_LEFT),
                'direccion' => 'Fixture transaccional',
                'ciudad' => 'Quito',
                'timezone' => 'America/Guayaquil',
                'telefono' => null,
                'correo' => null,
                'responsable' => null,
            ], $administrator->id);
            $this->createNativeEvents($administrator, $building->id());
            foreach ([
                'bitacora_orden_operativa',
                'bitacora_plan_mantenimiento',
                'bitacora_areas_comunes',
                'bitacora_comunicacion',
            ] as $auditTable) {
                $this->assertSame(1, DB::table($this->table($auditTable))->where('edificio_id', $building->id())->count(), $auditTable);
            }

            $page = app(AuditoriaRepositoryInterface::class)->paginateForUser($administrator->id, [
                'edificios' => [$building->id()],
            ]);

            $this->assertSame('pgsql', DB::connection()->getDriverName());
            $this->assertSame(5, $page['total']);
            $this->assertEqualsCanonicalizing(
                ['accesos', 'operaciones', 'mantenimiento', 'areas_comunes', 'comunicacion'],
                array_column($page['items'], 'fuente'),
            );
            $this->assertSame([$building->id()], array_values(array_unique(array_column($page['items'], 'edificioId'))));
            $this->assertSame(12, strlen($page['items'][0]['id']));
            foreach ($page['items'] as $event) {
                $this->assertStringEndsWith('Z', $event['ocurridoAt']);
            }
            $this->assertNotSame(
                DB::table($this->table('eventos_acceso_edificio'))->where('edificio_id', $building->id())->value('id'),
                collect($page['items'])->firstWhere('fuente', 'accesos')['id'],
            );
        } finally {
            DB::rollBack();
        }
    }

    private function table(string $name): string
    {
        return config('database.application_schema').'.'.$name;
    }

    private function createNativeEvents(UserEloquentModel $actor, string $buildingId): void
    {
        $this->actingAs($actor)->post(route('operaciones.store', $buildingId), [
            'tipo' => 'incidencia', 'titulo' => 'Incidencia PostgreSQL',
            'descripcion' => 'Fixture transaccional.', 'prioridad' => 'alta', 'fecha_objetivo' => null,
            'torre_id' => null, 'piso_id' => null, 'departamento_id' => null,
            'parqueadero_id' => null, 'bodega_id' => null, 'ubicacion_detalle' => 'Area tecnica',
            'reportante_residente_id' => null, 'proveedor_id' => null, 'contrato_id' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($actor)->post(route('mantenimiento-preventivo.store', $buildingId), [
            'codigo' => 'PLAN-PG-01', 'titulo' => 'Plan PostgreSQL',
            'descripcion' => 'Fixture transaccional.', 'prioridad' => 'media',
            'unidad_recurrencia' => 'mensual', 'intervalo_recurrencia' => 1, 'dias_anticipacion' => 2,
            'torre_id' => null, 'piso_id' => null, 'departamento_id' => null,
            'parqueadero_id' => null, 'bodega_id' => null, 'ubicacion_detalle' => 'Area tecnica',
            'proveedor_id' => null, 'contrato_id' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($actor)->post(route('areas-comunes.store', $buildingId), [
            'codigo' => 'AREA-PG-01', 'nombre' => 'Area PostgreSQL',
            'descripcion' => 'Fixture transaccional.', 'capacidad' => 20,
            'torre_id' => null, 'piso_id' => null, 'ubicacion_detalle' => 'Planta baja',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($actor)->post(route('comunicados.store', $buildingId), [
            'titulo' => 'Comunicado PostgreSQL', 'cuerpo' => 'Fixture transaccional.',
            'audiencias' => ['propietarios'],
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
}
