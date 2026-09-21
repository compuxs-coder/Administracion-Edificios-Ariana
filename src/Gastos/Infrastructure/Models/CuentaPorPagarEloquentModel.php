<?php

namespace Src\Gastos\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Gastos\Domain\Enums\EstadoCuentaPorPagar;
use Src\Gastos\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class CuentaPorPagarEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $table = 'cuentas_por_pagar';

    protected $fillable = [
        'edificio_id', 'gasto_id', 'proveedor_id', 'fecha_vencimiento',
        'monto_original', 'saldo', 'estado', 'anulado_at',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('cuentas_por_pagar');
    }

    /** @return BelongsTo<GastoEloquentModel, $this> */
    public function gasto(): BelongsTo
    {
        return $this->belongsTo(GastoEloquentModel::class, 'gasto_id');
    }

    /** @return BelongsTo<ProveedorEloquentModel, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorEloquentModel::class, 'proveedor_id');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoCuentaPorPagar::class,
            'fecha_vencimiento' => 'immutable_date',
            'monto_original' => 'decimal:4',
            'saldo' => 'decimal:4',
            'anulado_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
