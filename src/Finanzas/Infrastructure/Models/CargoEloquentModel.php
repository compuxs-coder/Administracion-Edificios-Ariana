<?php

namespace Src\Finanzas\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Finanzas\Domain\Enums\EstadoCargo;
use Src\Finanzas\Domain\Enums\OrigenCargo;
use Src\Finanzas\Infrastructure\Models\Concerns\UsesApplicationSchema;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;

final class CargoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'periodo',
        'fecha_emision',
        'fecha_vencimiento',
        'descripcion',
        'valor_original',
        'saldo',
        'estado',
        'origen',
        'referencia_generacion',
        'metadata',
        'created_by',
        'anulado_por',
        'anulado_at',
        'motivo_anulacion',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('cargos');
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

    /** @return BelongsTo<TarifaConceptoEloquentModel, $this> */
    public function tarifa(): BelongsTo
    {
        return $this->belongsTo(TarifaConceptoEloquentModel::class, 'tarifa_id');
    }

    /** @return BelongsTo<PropietarioEloquentModel, $this> */
    public function propietario(): BelongsTo
    {
        return $this->belongsTo(PropietarioEloquentModel::class, 'propietario_id');
    }

    /** @return BelongsTo<LoteGeneracionCargoEloquentModel, $this> */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteGeneracionCargoEloquentModel::class, 'lote_generacion_id');
    }

    /** @return HasMany<AplicacionPagoEloquentModel, $this> */
    public function aplicacionesPago(): HasMany
    {
        return $this->hasMany(AplicacionPagoEloquentModel::class, 'cargo_id');
    }

    protected function casts(): array
    {
        return [
            'periodo' => 'immutable_date',
            'fecha_emision' => 'immutable_date',
            'fecha_vencimiento' => 'immutable_date',
            'valor_original' => 'decimal:4',
            'saldo' => 'decimal:4',
            'estado' => EstadoCargo::class,
            'origen' => OrigenCargo::class,
            'metadata' => 'array',
            'anulado_at' => 'immutable_datetime',
        ];
    }
}
