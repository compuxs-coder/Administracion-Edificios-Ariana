<?php

namespace Src\Operaciones\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Src\Operaciones\Domain\Enums\EstadoOcurrenciaMantenimientoPreventivo;
use Src\Operaciones\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class OcurrenciaMantenimientoPreventivoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['edificio_id', 'plan_id', 'fecha_programada', 'estado', 'motivo', 'procesada_at'];

    public function getTable(): string
    {
        return $this->qualifiedTable('ocurrencias_mantenimiento_preventivo');
    }

    /** @return BelongsTo<PlanMantenimientoPreventivoEloquentModel, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanMantenimientoPreventivoEloquentModel::class, 'plan_id');
    }

    /** @return HasOne<OrdenOperativaEloquentModel, $this> */
    public function orden(): HasOne
    {
        return $this->hasOne(OrdenOperativaEloquentModel::class, 'ocurrencia_mantenimiento_id');
    }

    protected function casts(): array
    {
        return [
            'fecha_programada' => 'immutable_date',
            'estado' => EstadoOcurrenciaMantenimientoPreventivo::class,
            'procesada_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
