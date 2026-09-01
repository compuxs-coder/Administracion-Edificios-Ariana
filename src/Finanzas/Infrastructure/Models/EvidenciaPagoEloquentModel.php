<?php

namespace Src\Finanzas\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Finanzas\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class EvidenciaPagoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['nombre_original', 'mime_type', 'tamano_bytes', 'sha256', 'ruta_privada', 'descripcion', 'subido_por'];

    public function getTable(): string
    {
        return $this->qualifiedTable('evidencias_pago');
    }

    /** @return BelongsTo<PagoEloquentModel, $this> */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoEloquentModel::class, 'pago_id');
    }

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'subido_por');
    }
}
