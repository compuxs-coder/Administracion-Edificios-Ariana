<?php

namespace Src\Finanzas\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Finanzas\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class AplicacionPagoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['monto_aplicado'];

    public function getTable(): string
    {
        return $this->qualifiedTable('aplicaciones_pago');
    }

    /** @return BelongsTo<PagoEloquentModel, $this> */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoEloquentModel::class, 'pago_id');
    }

    /** @return BelongsTo<CargoEloquentModel, $this> */
    public function cargo(): BelongsTo
    {
        return $this->belongsTo(CargoEloquentModel::class, 'cargo_id');
    }

    protected function casts(): array
    {
        return ['monto_aplicado' => 'decimal:4'];
    }
}
