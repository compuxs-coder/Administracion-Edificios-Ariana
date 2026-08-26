<?php

namespace Src\Propiedad\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Domain\Enums\EstadoTitularidad;
use Src\Propiedad\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class DepartamentoPropietarioEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'porcentaje',
        'nombre_propietario',
        'tipo_identificacion_snapshot',
        'identificacion_snapshot',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'observaciones',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('departamento_propietarios');
    }

    /** @return BelongsTo<EdificioEloquentModel, $this> */
    public function edificio(): BelongsTo
    {
        return $this->belongsTo(EdificioEloquentModel::class, 'edificio_id');
    }

    /** @return BelongsTo<DepartamentoEloquentModel, $this> */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(DepartamentoEloquentModel::class, 'departamento_id');
    }

    /** @return BelongsTo<PropietarioEloquentModel, $this> */
    public function propietario(): BelongsTo
    {
        return $this->belongsTo(PropietarioEloquentModel::class, 'propietario_id');
    }

    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:6',
            'fecha_inicio' => 'immutable_date',
            'fecha_fin' => 'immutable_date',
            'estado' => EstadoTitularidad::class,
        ];
    }
}
