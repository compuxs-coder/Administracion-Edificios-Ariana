<?php

namespace Src\Gastos\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Gastos\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class AplicacionDesembolsoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $table = 'aplicaciones_desembolso';

    protected $fillable = [
        'edificio_id', 'proveedor_id', 'desembolso_id', 'cuenta_por_pagar_id', 'monto_aplicado',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('aplicaciones_desembolso');
    }

    /** @return BelongsTo<DesembolsoEloquentModel, $this> */
    public function desembolso(): BelongsTo
    {
        return $this->belongsTo(DesembolsoEloquentModel::class, 'desembolso_id');
    }

    /** @return BelongsTo<CuentaPorPagarEloquentModel, $this> */
    public function cuentaPorPagar(): BelongsTo
    {
        return $this->belongsTo(CuentaPorPagarEloquentModel::class, 'cuenta_por_pagar_id');
    }

    protected function casts(): array
    {
        return [
            'monto_aplicado' => 'decimal:4',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
