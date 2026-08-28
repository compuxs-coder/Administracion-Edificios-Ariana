<?php

namespace Src\Finanzas\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Finanzas\Infrastructure\Models\Concerns\UsesApplicationSchema;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;

final class PagoTitularEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['nombre_snapshot', 'identificacion_snapshot', 'porcentaje'];

    public function getTable(): string
    {
        return $this->qualifiedTable('pago_titulares');
    }

    /** @return BelongsTo<PagoEloquentModel, $this> */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoEloquentModel::class, 'pago_id');
    }

    /** @return BelongsTo<PropietarioEloquentModel, $this> */
    public function propietario(): BelongsTo
    {
        return $this->belongsTo(PropietarioEloquentModel::class, 'propietario_id');
    }

    protected function casts(): array
    {
        return ['porcentaje' => 'decimal:6'];
    }
}
