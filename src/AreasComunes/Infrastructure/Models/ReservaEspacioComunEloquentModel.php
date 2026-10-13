<?php

namespace Src\AreasComunes\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\AreasComunes\Domain\Enums\EstadoReservaEspacioComun;
use Src\AreasComunes\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class ReservaEspacioComunEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['edificio_id', 'espacio_comun_id', 'regla_id', 'ocupacion_id', 'departamento_id', 'residente_id', 'reservante_snapshot', 'regla_snapshot', 'inicio_at', 'fin_at', 'bloqueo_inicio_at', 'bloqueo_fin_at', 'cantidad_asistentes', 'estado', 'observaciones', 'creada_por_user_id', 'resuelta_por_user_id', 'resuelta_at', 'motivo_resolucion'];

    public function getTable(): string
    {
        return $this->qualifiedTable('reservas_espacio_comun');
    }

    /** @return BelongsTo<EspacioComunEloquentModel, $this> */
    public function espacio(): BelongsTo
    {
        return $this->belongsTo(EspacioComunEloquentModel::class, 'espacio_comun_id');
    }

    /** @return BelongsTo<ReglaEspacioComunEloquentModel, $this> */
    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaEspacioComunEloquentModel::class, 'regla_id');
    }

    protected function casts(): array
    {
        return [
            'reservante_snapshot' => 'array',
            'regla_snapshot' => 'array',
            'inicio_at' => 'immutable_datetime',
            'fin_at' => 'immutable_datetime',
            'bloqueo_inicio_at' => 'immutable_datetime',
            'bloqueo_fin_at' => 'immutable_datetime',
            'estado' => EstadoReservaEspacioComun::class,
            'resuelta_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
