<?php

namespace Src\Finanzas\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Finanzas\Domain\Enums\AlcanceTarifa;
use Src\Finanzas\Domain\Enums\BaseCalculoInteres;
use Src\Finanzas\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class TarifaConceptoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'valor',
        'porcentaje',
        'monto_total',
        'numero_cuotas',
        'unidad',
        'base_calculo',
        'fecha_inicio',
        'fecha_fin',
        'alcance',
        'observacion',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('tarifas_concepto');
    }

    /** @return BelongsTo<ConceptoCobroEloquentModel, $this> */
    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoCobroEloquentModel::class, 'concepto_cobro_id');
    }

    /** @return BelongsToMany<DepartamentoEloquentModel, $this> */
    public function departamentos(): BelongsToMany
    {
        return $this->belongsToMany(
            DepartamentoEloquentModel::class,
            $this->qualifiedTable('tarifa_departamentos'),
            'tarifa_id',
            'departamento_id',
        )->withPivot('edificio_id')->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:4',
            'porcentaje' => 'decimal:6',
            'monto_total' => 'decimal:4',
            'numero_cuotas' => 'integer',
            'fecha_inicio' => 'immutable_date',
            'fecha_fin' => 'immutable_date',
            'alcance' => AlcanceTarifa::class,
            'base_calculo' => BaseCalculoInteres::class,
        ];
    }
}
