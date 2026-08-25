<?php

namespace Src\Edificio\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Edificio\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class DepartamentoBodegaEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['fecha_inicio', 'fecha_fin'];

    public function getTable(): string
    {
        return $this->qualifiedTable('departamento_bodegas');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(DepartamentoEloquentModel::class, 'departamento_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(BodegaEloquentModel::class, 'bodega_id');
    }

    protected function casts(): array
    {
        return ['fecha_inicio' => 'immutable_datetime', 'fecha_fin' => 'immutable_datetime'];
    }
}
