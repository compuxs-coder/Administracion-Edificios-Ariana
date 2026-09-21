<?php

namespace Src\Gastos\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Enums\EstadoGasto;
use Src\Gastos\Domain\Enums\EstadoPagoGasto;
use Src\Gastos\Domain\Enums\TipoPagoGasto;
use Src\Gastos\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class GastoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'edificio_id', 'proveedor_id', 'contrato_id', 'numero', 'fecha_gasto', 'fecha_vencimiento',
        'concepto', 'referencia', 'monto', 'tipo_pago', 'estado_pago', 'pagado_at', 'observaciones',
        'estado', 'proveedor_snapshot', 'contrato_snapshot', 'registrado_por', 'registrado_at',
        'anulado_por', 'anulado_at', 'motivo_anulacion',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('gastos');
    }

    /** @return BelongsTo<EdificioEloquentModel, $this> */
    public function edificio(): BelongsTo
    {
        return $this->belongsTo(EdificioEloquentModel::class, 'edificio_id');
    }

    /** @return BelongsTo<ProveedorEloquentModel, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorEloquentModel::class, 'proveedor_id');
    }

    /** @return BelongsTo<ContratoProveedorEloquentModel, $this> */
    public function contrato(): BelongsTo
    {
        return $this->belongsTo(ContratoProveedorEloquentModel::class, 'contrato_id');
    }

    /** @return HasOne<CuentaPorPagarEloquentModel, $this> */
    public function cuentaPorPagar(): HasOne
    {
        return $this->hasOne(CuentaPorPagarEloquentModel::class, 'gasto_id');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoGasto::class,
            'tipo_pago' => TipoPagoGasto::class,
            'estado_pago' => EstadoPagoGasto::class,
            'fecha_gasto' => 'immutable_date',
            'fecha_vencimiento' => 'immutable_date',
            'monto' => 'decimal:4',
            'proveedor_snapshot' => 'array',
            'contrato_snapshot' => 'array',
            'pagado_at' => 'immutable_datetime',
            'registrado_at' => 'immutable_datetime',
            'anulado_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
