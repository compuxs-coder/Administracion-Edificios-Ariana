<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Tests\TestCase;

class FacturaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_facturas_require_authentication(): void
    {
        $this->getJson('/api/v1/facturas')
            ->assertUnauthorized();
    }

    public function test_it_performs_the_complete_factura_crud(): void
    {
        $this->authenticate();

        $createResponse = $this->postJson('/api/v1/facturas', $this->payload());

        $createResponse
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme Ecuador')
            ->assertJsonPath('data.ruc', '1790012345001')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'ruc',
                    'email',
                    'phone',
                    'address',
                    'city',
                    'country',
                    'status',
                    'created_at',
                ],
            ]);

        $id = $createResponse->json('data.id');

        $this->getJson('/api/v1/facturas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);

        $this->getJson("/api/v1/facturas/{$id}")
            ->assertOk()
            ->assertJsonPath('data.email', 'billing@acme.test');

        $this->patchJson("/api/v1/facturas/{$id}", [
            'email' => 'invoices@acme.test',
            'status' => 'inactive',
        ])
            ->assertOk()
            ->assertJsonPath('data.email', 'invoices@acme.test')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.name', 'Acme Ecuador');

        $this->assertDatabaseHas('facturas', [
            'id' => $id,
            'email' => 'invoices@acme.test',
            'status' => 'inactive',
        ]);

        $this->deleteJson("/api/v1/facturas/{$id}")
            ->assertOk()
            ->assertJsonPath('message', 'Factura eliminada exitosamente.');

        $this->assertDatabaseMissing('facturas', ['id' => $id]);
    }

    public function test_it_validates_required_fields_and_unique_ruc(): void
    {
        $this->authenticate();

        $this->postJson('/api/v1/facturas', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'ruc',
                'email',
                'phone',
                'address',
                'city',
                'country',
                'status',
            ]);

        $this->postJson('/api/v1/facturas', $this->payload())
            ->assertCreated();

        $this->postJson('/api/v1/facturas', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ruc');
    }

    public function test_it_returns_not_found_for_an_unknown_factura(): void
    {
        $this->authenticate();

        $this->getJson('/api/v1/facturas/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertJsonPath(
                'message',
                'Factura con id 00000000-0000-0000-0000-000000000000 no encontrada.',
            );
    }

    private function authenticate(): void
    {
        $user = UserEloquentModel::query()->create([
            'name' => 'API Tester',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
        ]);

        Sanctum::actingAs($user);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'name' => 'Acme Ecuador',
            'ruc' => '1790012345001',
            'email' => 'billing@acme.test',
            'phone' => '+593 2 555 0100',
            'address' => 'Av. Naciones Unidas 123',
            'city' => 'Quito',
            'country' => 'Ecuador',
            'status' => 'active',
        ];
    }
}
