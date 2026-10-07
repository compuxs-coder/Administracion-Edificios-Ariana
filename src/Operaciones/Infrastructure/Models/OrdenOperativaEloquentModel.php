<?php

namespace Src\Operaciones\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Src\Operaciones\Domain\Enums\EstadoOrdenOperativa;
use Src\Operaciones\Domain\Enums\OrigenOrdenOperativa;
use Src\Operaciones\Domain\Enums\PrioridadOrdenOperativa;
use Src\Operaciones\Domain\Enums\TipoActorOperativo;
use Src\Operaciones\Domain\Enums\TipoOrdenOperativa;
use Src\Operaciones\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class OrdenOperativaEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'edificio_id', 'numero', 'tipo', 'origen', 'ocurrencia_mantenimiento_id', 'titulo', 'descripcion', 'prioridad', 'fecha_objetivo',
        'estado', 'torre_id', 'piso_id', 'departamento_id', 'parqueadero_id', 'bodega_id',
        'ubicacion_detalle', 'reportante_residente_id', 'reportante_snapshot', 'proveedor_id',
        'contrato_id', 'creada_por_user_id', 'creada_por_tipo', 'estado_actualizado_por', 'estado_actualizado_at',
        'motivo_estado',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('ordenes_operativas');
    }

    /** @return HasMany<AsignacionOrdenOperativaEloquentModel, $this> */
    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionOrdenOperativaEloquentModel::class, 'orden_operativa_id');
    }

    /** @return HasOne<AsignacionOrdenOperativaEloquentModel, $this> */
    public function asignacionActual(): HasOne
    {
        return $this->hasOne(AsignacionOrdenOperativaEloquentModel::class, 'orden_operativa_id')->whereNull('fecha_fin');
    }

    /** @return HasMany<EvidenciaOrdenOperativaEloquentModel, $this> */
    public function evidencias(): HasMany
    {
        return $this->hasMany(EvidenciaOrdenOperativaEloquentModel::class, 'orden_operativa_id');
    }

    /** @return HasMany<BitacoraOrdenOperativaEloquentModel, $this> */
    public function bitacora(): HasMany
    {
        return $this->hasMany(BitacoraOrdenOperativaEloquentModel::class, 'orden_operativa_id');
    }

    /** @return HasOne<OcurrenciaMantenimientoPreventivoEloquentModel, $this> */
    public function ocurrenciaMantenimiento(): HasOne
    {
        return $this->hasOne(OcurrenciaMantenimientoPreventivoEloquentModel::class, 'id', 'ocurrencia_mantenimiento_id');
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoOrdenOperativa::class,
            'origen' => OrigenOrdenOperativa::class,
            'creada_por_tipo' => TipoActorOperativo::class,
            'prioridad' => PrioridadOrdenOperativa::class,
            'estado' => EstadoOrdenOperativa::class,
            'fecha_objetivo' => 'immutable_date',
            'reportante_snapshot' => 'array',
            'estado_actualizado_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
