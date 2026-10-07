<?php

namespace Src\Operaciones\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Operaciones\Domain\Enums\EstadoPlanMantenimientoPreventivo;
use Src\Operaciones\Domain\Enums\PrioridadOrdenOperativa;
use Src\Operaciones\Domain\Enums\UnidadRecurrenciaMantenimiento;
use Src\Operaciones\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class PlanMantenimientoPreventivoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'edificio_id', 'codigo', 'titulo', 'descripcion', 'prioridad', 'unidad_recurrencia',
        'intervalo_recurrencia', 'dias_anticipacion', 'estado', 'fecha_ancla',
        'secuencia_siguiente', 'proxima_fecha_programada', 'torre_id', 'piso_id',
        'departamento_id', 'parqueadero_id', 'bodega_id', 'ubicacion_detalle',
        'proveedor_id', 'contrato_id', 'creado_por_user_id', 'actualizado_por_user_id',
        'estado_actualizado_por', 'estado_actualizado_at',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('planes_mantenimiento_preventivo');
    }

    /** @return HasMany<OcurrenciaMantenimientoPreventivoEloquentModel, $this> */
    public function ocurrencias(): HasMany
    {
        return $this->hasMany(OcurrenciaMantenimientoPreventivoEloquentModel::class, 'plan_id');
    }

    /** @return HasMany<BitacoraPlanMantenimientoEloquentModel, $this> */
    public function bitacora(): HasMany
    {
        return $this->hasMany(BitacoraPlanMantenimientoEloquentModel::class, 'plan_id');
    }

    protected function casts(): array
    {
        return [
            'prioridad' => PrioridadOrdenOperativa::class,
            'unidad_recurrencia' => UnidadRecurrenciaMantenimiento::class,
            'estado' => EstadoPlanMantenimientoPreventivo::class,
            'fecha_ancla' => 'immutable_date',
            'proxima_fecha_programada' => 'immutable_date',
            'estado_actualizado_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
