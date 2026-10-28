<?php

namespace Src\Auth\Infrastructure\Models;

use App\Traits\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

#[UseFactory(UserFactory::class)]
class UserEloquentModel extends Authenticatable
{
    public const API_TOKEN_ABILITIES = ['user:read', 'token:revoke'];

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasUuid;

    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function createApiToken(): NewAccessToken
    {
        $expirationMinutes = max(1, (int) (config('sanctum.expiration') ?? 1440));

        return $this->createToken(
            'auth_token',
            self::API_TOKEN_ABILITIES,
            now()->addMinutes($expirationMinutes),
        );
    }
}
