<?php

namespace Src\Edificio\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class PisoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = ['numero', 'nombre', 'orden', 'estado'];

    public function getTable(): string
    {
        return $this->qualifiedTable('pisos');
    }

    /** @return BelongsTo<EdificioEloquentModel, $this> */
    public function edificio(): BelongsTo
    {
        return $this->belongsTo(EdificioEloquentModel::class, 'edificio_id');
    }

    /** @return BelongsTo<TorreEloquentModel, $this> */
    public function torre(): BelongsTo
    {
        return $this->belongsTo(TorreEloquentModel::class, 'torre_id');
    }

    /** @return HasMany<DepartamentoEloquentModel, $this> */
    public function departamentos(): HasMany
    {
        return $this->hasMany(DepartamentoEloquentModel::class, 'piso_id');
    }

    protected function casts(): array
    {
        return ['orden' => 'integer', 'estado' => EstadoEstructura::class];
    }
}
