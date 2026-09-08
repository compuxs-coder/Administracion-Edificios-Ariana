<?php

namespace Src\Edificio\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Src\Edificio\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class EventoAccesoEdificioEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = [
        'edificio_id',
        'usuario_afectado_id',
        'actor_user_id',
        'invitacion_id',
        'tipo',
        'roles_anteriores',
        'roles_nuevos',
        'detalle',
        'created_at',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('eventos_acceso_edificio');
    }

    protected function casts(): array
    {
        return [
            'roles_anteriores' => 'array',
            'roles_nuevos' => 'array',
            'detalle' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
