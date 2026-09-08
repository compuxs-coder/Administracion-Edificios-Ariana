<?php

namespace Src\Edificio\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Src\Edificio\Domain\Enums\EstadoInvitacion;
use Src\Edificio\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class InvitacionEdificioEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'edificio_id',
        'email_normalizado',
        'rol_codigo',
        'token_hash',
        'estado',
        'invitado_por_user_id',
        'aceptado_por_user_id',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('invitaciones_edificio');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoInvitacion::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
