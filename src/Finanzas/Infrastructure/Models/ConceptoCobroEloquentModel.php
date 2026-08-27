<?php

namespace Src\Finanzas\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Domain\Enums\EstadoConceptoCobro;
use Src\Finanzas\Domain\Enums\FormaCalculoCobro;
use Src\Finanzas\Domain\Enums\PeriodicidadCobro;
use Src\Finanzas\Domain\Enums\TipoConceptoCobro;
use Src\Finanzas\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class ConceptoCobroEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'tipo',
        'periodicidad',
        'forma_calculo',
        'estado',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('conceptos_cobro');
    }

    /** @return BelongsTo<EdificioEloquentModel, $this> */
    public function edificio(): BelongsTo
    {
        return $this->belongsTo(EdificioEloquentModel::class, 'edificio_id');
    }

    /** @return HasMany<TarifaConceptoEloquentModel, $this> */
    public function tarifas(): HasMany
    {
        return $this->hasMany(TarifaConceptoEloquentModel::class, 'concepto_cobro_id');
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoConceptoCobro::class,
            'periodicidad' => PeriodicidadCobro::class,
            'forma_calculo' => FormaCalculoCobro::class,
            'estado' => EstadoConceptoCobro::class,
        ];
    }
}
