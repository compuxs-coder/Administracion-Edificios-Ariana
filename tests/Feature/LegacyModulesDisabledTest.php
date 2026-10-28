<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Tests\TestCase;

final class LegacyModulesDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_http_routes_are_not_registered(): void
    {
        foreach (['clientes', 'categorias', 'productos', 'facturas'] as $resource) {
            foreach (['index', 'store', 'show', 'update', 'destroy'] as $operation) {
                $this->assertFalse(Route::has("api.{$resource}.{$operation}"));
            }
        }

        foreach (['index', 'create', 'store', 'edit', 'update', 'destroy'] as $operation) {
            $this->assertFalse(Route::has("clientes.{$operation}"));
        }
    }

    public function test_two_building_users_cannot_access_or_mutate_preserved_legacy_data(): void
    {
        [$firstUser, $firstBuildingId] = $this->createUserWithBuilding(1);
        [$secondUser, $secondBuildingId] = $this->createUserWithBuilding(2);
        $legacyIds = $this->seedLegacyData();

        $this->assertNotSame($firstBuildingId, $secondBuildingId);
        $this->assertDatabaseCount('edificios', 2);
        $this->assertDatabaseCount('edificio_usuario', 2);

        foreach ([$firstUser, $secondUser] as $user) {
            Sanctum::actingAs($user, ['*']);

            foreach ($legacyIds as $resource => $id) {
                $this->getJson("/api/v1/{$resource}")->assertNotFound();
                $this->postJson("/api/v1/{$resource}", [])->assertNotFound();
                $this->getJson("/api/v1/{$resource}/{$id}")->assertNotFound();
                $this->patchJson("/api/v1/{$resource}/{$id}", [])->assertNotFound();
                $this->deleteJson("/api/v1/{$resource}/{$id}")->assertNotFound();
            }

            $this->actingAs($user)->get('/clientes')->assertNotFound();
            $this->actingAs($user)->get("/clientes/{$legacyIds['clientes']}/edit")->assertNotFound();
            $this->actingAs($user)->delete("/clientes/{$legacyIds['clientes']}")->assertNotFound();
        }

        $this->assertDatabaseHas('clientes', ['id' => $legacyIds['clientes'], 'razon_social' => 'Cliente preservado']);
        $this->assertDatabaseHas('categorias', ['id' => $legacyIds['categorias'], 'nombre' => 'Categoría preservada']);
        $this->assertDatabaseHas('productos', ['id' => $legacyIds['productos'], 'codigo' => 'LEG-001']);
        $this->assertDatabaseHas('facturas', ['id' => $legacyIds['facturas'], 'ruc' => '1790012345001']);
    }

    /** @return array{UserEloquentModel, string} */
    private function createUserWithBuilding(int $sequence): array
    {
        $user = UserEloquentModel::factory()->create();
        $building = $this->app->make(CreateEdificioAction::class)->execute([
            'nombre' => "Edificio de seguridad {$sequence}",
            'ruc' => "179001234500{$sequence}",
            'direccion' => "Calle {$sequence}",
            'ciudad' => 'Quito',
            'timezone' => 'America/Guayaquil',
        ], $user->id);

        return [$user, $building->id()];
    }

    /** @return array{clientes: string, categorias: string, productos: string, facturas: string} */
    private function seedLegacyData(): array
    {
        $now = now();
        $ids = [
            'clientes' => (string) Str::uuid(),
            'categorias' => (string) Str::uuid(),
            'productos' => (string) Str::uuid(),
            'facturas' => (string) Str::uuid(),
        ];

        DB::table('clientes')->insert([
            'id' => $ids['clientes'],
            'tipo_documento' => 'ruc',
            'numero_documento' => '1790012345001',
            'razon_social' => 'Cliente preservado',
            'direccion' => 'Calle histórica',
            'telefono' => '+593 2 555 0100',
            'email' => 'cliente-preservado@example.test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('categorias')->insert([
            'id' => $ids['categorias'],
            'nombre' => 'Categoría preservada',
            'descripcion' => null,
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('productos')->insert([
            'id' => $ids['productos'],
            'categoria_id' => $ids['categorias'],
            'codigo' => 'LEG-001',
            'nombre' => 'Producto preservado',
            'descripcion' => null,
            'precio_unitario' => '10.0000',
            'stock' => 1,
            'tipo' => 'bien',
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('facturas')->insert([
            'id' => $ids['facturas'],
            'name' => 'Factura preservada',
            'ruc' => '1790012345001',
            'email' => 'factura-preservada@example.test',
            'phone' => '+593 2 555 0100',
            'address' => 'Calle histórica',
            'city' => 'Quito',
            'country' => 'Ecuador',
            'status' => 'active',
            'created_at' => $now,
        ]);

        return $ids;
    }
}
