<?php

namespace Src\Comunicacion\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Comunicacion\Domain\Enums\CanalNotificacionComunicado;
use Src\Comunicacion\Domain\Enums\EstadoEntregaComunicado;
use Src\Comunicacion\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class EntregaComunicadoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'edificio_id', 'comunicado_id', 'canal', 'destino_normalizado', 'estado', 'intentos',
        'disponible_at', 'lease_until', 'ultimo_intento_at', 'enviado_at',
        'proveedor_mensaje_id', 'error_codigo', 'error_detalle',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('entregas_comunicado');
    }

    /** @return HasMany<DestinatarioComunicadoEloquentModel, $this> */
    public function destinatarios(): HasMany
    {
        return $this->hasMany(DestinatarioComunicadoEloquentModel::class, 'entrega_id');
    }

    protected function casts(): array
    {
        return [
            'canal' => CanalNotificacionComunicado::class,
            'estado' => EstadoEntregaComunicado::class,
            'intentos' => 'integer',
            'disponible_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'ultimo_intento_at' => 'immutable_datetime',
            'enviado_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
