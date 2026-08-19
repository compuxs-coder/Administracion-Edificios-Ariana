<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Src\Auth\Domain\Contracts\UserRepositoryInterface;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Tests\TestCase;

class TechnicalFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_clientes_show_route_is_not_registered_without_a_controller_action(): void
    {
        $this->assertFalse(Route::has('clientes.show'));
    }

    public function test_user_factory_resolves_the_authentication_model(): void
    {
        $user = UserEloquentModel::factory()->create();

        $this->assertInstanceOf(UserEloquentModel::class, $user);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_web_login_uses_the_session_and_remember_cookie_without_an_api_token(): void
    {
        $user = UserEloquentModel::query()->create([
            'name' => 'Web Tester',
            'email' => 'web@example.test',
            'password' => 'password',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $response
            ->assertRedirect(route('dashboard'))
            ->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->getRememberToken());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_web_login_rejects_invalid_credentials(): void
    {
        $user = UserEloquentModel::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_web_login_cleans_the_session_when_the_repository_fails(): void
    {
        $user = UserEloquentModel::factory()->create();
        $repository = Mockery::mock(UserRepositoryInterface::class);
        $repository
            ->shouldReceive('findByEmail')
            ->once()
            ->with($user->email)
            ->andThrow(new RuntimeException('Repository unavailable'));
        $this->app->instance(UserRepositoryInterface::class, $repository);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_web_registration_creates_a_session_without_an_api_token(): void
    {
        $this->post('/register', [
            'name' => 'Web Registration',
            'email' => 'web-registration@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'web-registration@example.test']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_web_logout_ends_the_session(): void
    {
        $user = UserEloquentModel::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_api_login_still_creates_a_sanctum_token(): void
    {
        $user = UserEloquentModel::query()->create([
            'name' => 'API Tester',
            'email' => 'api@example.test',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_api_login_returns_a_controlled_error_when_the_repository_fails(): void
    {
        $user = UserEloquentModel::factory()->create();
        $repository = Mockery::mock(UserRepositoryInterface::class);
        $repository
            ->shouldReceive('findByEmail')
            ->once()
            ->with($user->email)
            ->andThrow(new RuntimeException('Repository unavailable'));
        $this->app->instance(UserRepositoryInterface::class, $repository);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertInternalServerError()
            ->assertExactJson([
                'success' => false,
                'message' => 'No fue posible iniciar sesión. Inténtalo nuevamente.',
            ]);

        $this->assertGuest();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_api_registration_creates_a_user_and_sanctum_token(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'API Registration',
            'email' => 'api-registration@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['access_token']]);

        $this->assertDatabaseHas('users', ['email' => 'api-registration@example.test']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_api_logout_revokes_the_current_token(): void
    {
        $user = UserEloquentModel::factory()->create();
        $plainTextToken = $user->createToken('test-token')->plainTextToken;

        $this->withToken($plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
