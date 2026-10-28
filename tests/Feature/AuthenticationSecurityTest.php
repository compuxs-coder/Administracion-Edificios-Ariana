<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Validated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Tests\TestCase;

final class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_issues_expiring_tokens_with_only_the_required_abilities(): void
    {
        $user = UserEloquentModel::factory()->create();
        $issuedAt = now();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $token = PersonalAccessToken::query()->sole();
        $this->assertSame(UserEloquentModel::API_TOKEN_ABILITIES, $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(
            $issuedAt->addMinutes((int) config('sanctum.expiration'))->subSeconds(2),
            now()->addMinutes((int) config('sanctum.expiration'))->addSeconds(2),
        ));

        $plainTextToken = $response->json('data.access_token');
        $this->withToken($plainTextToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($plainTextToken)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_api_login_is_stateless_and_preserves_password_rehash_and_events(): void
    {
        $user = UserEloquentModel::factory()->create([
            'password' => Hash::make('password', ['rounds' => 4]),
        ]);
        config(['hashing.bcrypt.rounds' => 5]);
        Hash::driver('bcrypt')->setRounds(5);
        Event::fake([Attempting::class, Login::class, Validated::class]);

        $this->assertTrue(Hash::needsRehash($user->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertGuest();
        $this->assertFalse(Hash::needsRehash($user->fresh()->password));
        Event::assertDispatched(Attempting::class);
        Event::assertDispatched(Validated::class);
        Event::assertDispatched(Login::class, fn (Login $event): bool => $event->user->is($user) && ! $event->remember);
    }

    public function test_api_enforces_token_abilities_and_expiration(): void
    {
        $user = UserEloquentModel::factory()->create();
        $readOnly = $user->createToken('read-only', ['user:read'], now()->addHour())->plainTextToken;
        $revokeOnly = $user->createToken('revoke-only', ['token:revoke'], now()->addHour())->plainTextToken;
        $expired = $user->createToken('expired', UserEloquentModel::API_TOKEN_ABILITIES, now()->subMinute())->plainTextToken;

        $this->withToken($readOnly)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($readOnly)->postJson('/api/v1/auth/logout')->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($revokeOnly)->getJson('/api/v1/auth/me')->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($expired)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_api_login_is_rate_limited_by_identity_and_ip(): void
    {
        $user = UserEloquentModel::factory()->create();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'incorrect-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertStatus(429);
    }

    public function test_web_login_uses_the_same_rate_limit(): void
    {
        $user = UserEloquentModel::factory()->create();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.11']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'incorrect-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertStatus(429);
    }

    public function test_api_registration_is_rate_limited_by_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.12']);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/v1/auth/register', [
                'name' => "Registration {$attempt}",
                'email' => "registration-{$attempt}@example.test",
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Registration blocked',
            'email' => 'registration-blocked@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(429);
    }

    public function test_web_registration_uses_the_same_rate_limit(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.13']);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post('/register', [
                'name' => "Web Registration {$attempt}",
                'email' => "web-registration-{$attempt}@example.test",
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertRedirect(route('dashboard'));

            $this->post('/logout')->assertRedirect(route('login'));
        }

        $this->post('/register', [
            'name' => 'Web Registration blocked',
            'email' => 'web-registration-blocked@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(429);
    }
}
