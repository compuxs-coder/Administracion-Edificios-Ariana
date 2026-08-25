<?php

namespace Src\Edificio\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class BodegaEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['codigo', 'ubicacion', 'estado'];

    public function getTable(): string
    {
        return $this->qualifiedTable('bodegas');
    }

    public function edificio(): BelongsTo
    {
        return $this->belongsTo(EdificioEloquentModel::class, 'edificio_id');
    }

    public function torre(): BelongsTo
    {
        return $this->belongsTo(TorreEloquentModel::class, 'torre_id');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(DepartamentoBodegaEloquentModel::class, 'bodega_id');
    }

    protected function casts(): array
    {
        return ['estado' => EstadoEstructura::class];
    }
}
