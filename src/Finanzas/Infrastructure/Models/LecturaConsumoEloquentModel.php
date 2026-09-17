<?php

namespace Src\Finanzas\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class LecturaConsumoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'periodo',
        'fecha_lectura',
        'lectura_anterior',
        'lectura_actual',
        'consumo',
        'unidad',
        'observacion',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('lecturas_consumo');
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

    /** @return BelongsTo<ConceptoCobroEloquentModel, $this> */
    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoCobroEloquentModel::class, 'concepto_cobro_id');
    }

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'registrado_por');
    }

    protected function casts(): array
    {
        return [
            'periodo' => 'immutable_date',
            'fecha_lectura' => 'immutable_date',
            'lectura_anterior' => 'decimal:4',
            'lectura_actual' => 'decimal:4',
            'consumo' => 'decimal:4',
        ];
    }
}
