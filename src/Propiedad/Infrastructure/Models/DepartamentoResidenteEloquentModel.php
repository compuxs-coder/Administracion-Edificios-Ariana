<?php

namespace Src\Propiedad\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Domain\Enums\EstadoOcupacion;
use Src\Propiedad\Domain\Enums\TipoOcupacion;
use Src\Propiedad\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class DepartamentoResidenteEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'tipo_ocupacion',
        'nombre_residente',
        'tipo_identificacion_snapshot',
        'identificacion_snapshot',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'observaciones',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('departamento_residentes');
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

    /** @return BelongsTo<ResidenteEloquentModel, $this> */
    public function residente(): BelongsTo
    {
        return $this->belongsTo(ResidenteEloquentModel::class, 'residente_id');
    }

    protected function casts(): array
    {
        return [
            'tipo_ocupacion' => TipoOcupacion::class,
            'fecha_inicio' => 'immutable_date',
            'fecha_fin' => 'immutable_date',
            'estado' => EstadoOcupacion::class,
        ];
    }
}
