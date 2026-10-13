<?php

namespace Src\AreasComunes\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\AreasComunes\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class ReglaEspacioComunEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = ['edificio_id', 'espacio_comun_id', 'vigente_desde', 'vigente_hasta', 'timezone', 'requiere_aprobacion', 'duracion_minima_minutos', 'duracion_maxima_minutos', 'antelacion_minima_minutos', 'antelacion_maxima_dias', 'buffer_antes_minutos', 'buffer_despues_minutos', 'aforo_maximo', 'reglamento', 'creada_por_user_id', 'created_at'];

    public function getTable(): string
    {
        return $this->qualifiedTable('reglas_espacio_comun');
    }

    /** @return HasMany<HorarioReglaEspacioComunEloquentModel, $this> */
    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioReglaEspacioComunEloquentModel::class, 'regla_id');
    }

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'immutable_date',
            'vigente_hasta' => 'immutable_date',
            'requiere_aprobacion' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
