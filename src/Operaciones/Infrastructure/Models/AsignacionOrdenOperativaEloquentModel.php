<?php

namespace Src\Operaciones\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Operaciones\Domain\Enums\TipoResponsableOrdenOperativa;
use Src\Operaciones\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class AsignacionOrdenOperativaEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = [
        'edificio_id', 'orden_operativa_id', 'tipo_responsable', 'responsable_user_id',
        'responsable_proveedor_id', 'responsable_snapshot', 'asignado_por_user_id',
        'finalizado_por_user_id', 'fecha_inicio', 'fecha_fin',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('asignaciones_orden_operativa');
    }

    /** @return BelongsTo<OrdenOperativaEloquentModel, $this> */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenOperativaEloquentModel::class, 'orden_operativa_id');
    }

    protected function casts(): array
    {
        return [
            'tipo_responsable' => TipoResponsableOrdenOperativa::class,
            'responsable_snapshot' => 'array',
            'fecha_inicio' => 'immutable_datetime',
            'fecha_fin' => 'immutable_datetime',
        ];
    }
}
