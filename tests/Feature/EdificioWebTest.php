<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Application\Actions\CreateEdificioAction;
use Src\Edificio\Domain\Enums\EstadoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Tests\TestCase;

final class EdificioWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_creates_an_active_building_and_receives_access(): void
    {
        $user = UserEloquentModel::factory()->create();

        $this->actingAs($user)
            ->post(route('edificios.store'), $this->validData())
            ->assertRedirect(route('edificios.index'))
            ->assertSessionHas('success');

        $edificio = EdificioEloquentModel::query()->sole();

        $this->assertSame(EstadoEdificio::ACTIVO, $edificio->estado);
        $this->assertDatabaseHas('edificios', [
            'id' => $edificio->id,
            'nombre' => 'Edificio Central',
            'estado' => 'activo',
        ]);
        $this->assertDatabaseHas('edificio_usuario', [
            'edificio_id' => $edificio->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_building_creation_validates_required_fields_and_unique_ruc(): void
    {
        $user = UserEloquentModel::factory()->create();
        $this->createAssignedEdificio($user);

        $this->actingAs($user)
            ->post(route('edificios.store'), [
                'nombre' => '',
                'ruc' => '1790012345001',
                'direccion' => '',
                'ciudad' => '',
                'correo' => 'correo-invalido',
            ])
            ->assertSessionHasErrors(['nombre', 'ruc', 'direccion', 'ciudad', 'correo']);

        $this->assertDatabaseCount('edificios', 1);
    }

    public function test_assigned_user_can_view_and_update_a_building(): void
    {
        $user = UserEloquentModel::factory()->create();
        $edificio = $this->createAssignedEdificio($user);

        $this->actingAs($user)
            ->get(route('edificios.show', $edificio))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Edificio/show')
                ->where('edificio.id', $edificio->id)
                ->where('edificio.nombre', 'Edificio Central'));

        $this->actingAs($user)
            ->put(route('edificios.update', $edificio), $this->validData([
                'nombre' => 'Edificio Central Renovado',
                'ciudad' => 'Guayaquil',
            ]))
            ->assertRedirect(route('edificios.show', $edificio));

        $this->assertDatabaseHas('edificios', [
            'id' => $edificio->id,
            'nombre' => 'Edificio Central Renovado',
            'ciudad' => 'Guayaquil',
        ]);
    }

    public function test_assigned_user_can_inactivate_and_reactivate_a_building(): void
    {
        $user = UserEloquentModel::factory()->create();
        $edificio = $this->createAssignedEdificio($user);

        $this->actingAs($user)
            ->patch(route('edificios.estado', $edificio), ['estado' => 'inactivo'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('edificios', [
            'id' => $edificio->id,
            'estado' => 'inactivo',
        ]);

        $this->actingAs($user)
            ->patch(route('edificios.estado', $edificio), ['estado' => 'activo'])
            ->assertRedirect();

        $this->assertDatabaseHas('edificios', [
            'id' => $edificio->id,
            'estado' => 'activo',
        ]);
    }

    public function test_user_cannot_access_or_mutate_an_unassigned_building(): void
    {
        $owner = UserEloquentModel::factory()->create();
        $outsider = UserEloquentModel::factory()->create();
        $edificio = $this->createAssignedEdificio($owner);

        $this->actingAs($outsider)
            ->get(route('edificios.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Edificio/index')
                ->has('edificios.data', 0));

        $this->actingAs($outsider)
            ->get(route('edificios.show', $edificio))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->put(route('edificios.update', $edificio), $this->validData())
            ->assertForbidden();

        $this->actingAs($outsider)
            ->patch(route('edificios.estado', $edificio), ['estado' => 'inactivo'])
            ->assertForbidden();

        $this->assertDatabaseHas('edificios', [
            'id' => $edificio->id,
            'estado' => 'activo',
        ]);
    }

    public function test_building_search_accepts_zero_as_a_valid_term(): void
    {
        $user = UserEloquentModel::factory()->create();
        $expected = $this->createAssignedEdificio($user, [
            'nombre' => 'Torre 0',
            'ruc' => '1790012345002',
        ]);
        $this->createAssignedEdificio($user, [
            'nombre' => 'Torre Norte',
            'ruc' => 'ABC123456789',
        ]);

        $this->actingAs($user)
            ->get(route('edificios.index', ['buscar' => '0']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Edificio/index')
                ->has('edificios.data', 1)
                ->where('edificios.data.0.id', $expected->id));
    }

    public function test_database_constraints_protect_ruc_and_user_assignment_integrity(): void
    {
        $user = UserEloquentModel::factory()->create();
        $this->createAssignedEdificio($user);

        try {
            DB::transaction(fn () => EdificioEloquentModel::query()->create([
                ...$this->validData(),
                'estado' => EstadoEdificio::ACTIVO,
            ]));
            $this->fail('La base de datos permitió un RUC duplicado.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('ruc', strtolower($exception->getMessage()));
            $this->assertDatabaseCount('edificios', 1);
        }

        $edificio = EdificioEloquentModel::query()->sole();

        try {
            DB::transaction(static fn () => DB::table('edificio_usuario')->insert([
                'edificio_id' => $edificio->id,
                'user_id' => '00000000-0000-0000-0000-000000000000',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->fail('La base de datos permitió asignar un usuario inexistente.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
            $this->assertDatabaseCount('edificio_usuario', 1);
        }

        $this->assertFalse(Route::has('edificios.destroy'));
    }

    public function test_building_creation_rolls_back_when_user_assignment_fails(): void
    {
        /** @var CreateEdificioAction $action */
        $action = $this->app->make(CreateEdificioAction::class);

        try {
            $action->execute(
                $this->validData(),
                '00000000-0000-0000-0000-000000000000',
            );
            $this->fail('La creación no revirtió una asignación inválida.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
        }

        $this->assertDatabaseCount('edificios', 0);
        $this->assertDatabaseCount('edificio_usuario', 0);
    }

    /** @param array<string, string> $overrides */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Edificio Central',
            'ruc' => '1790012345001',
            'direccion' => 'Av. Principal 123',
            'ciudad' => 'Quito',
            'telefono' => '+593 2 555 0100',
            'correo' => 'administracion@central.test',
            'responsable' => 'Ana Administradora',
        ], $overrides);
    }

    /** @param array<string, string> $overrides */
    private function createAssignedEdificio(
        UserEloquentModel $user,
        array $overrides = [],
    ): EdificioEloquentModel {
        $edificio = EdificioEloquentModel::query()->create([
            ...$this->validData($overrides),
            'estado' => EstadoEdificio::ACTIVO,
        ]);
        $edificio->usuarios()->attach($user->id);

        return $edificio;
    }
}
