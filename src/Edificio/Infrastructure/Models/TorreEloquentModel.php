<?php

namespace Src\Edificio\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Edificio\Domain\Enums\EstadoEstructura;
use Src\Edificio\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class TorreEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'es_predeterminada',
        'estado',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('torres');
    }

    /** @return BelongsTo<EdificioEloquentModel, $this> */
    public function edificio(): BelongsTo
    {
        return $this->belongsTo(EdificioEloquentModel::class, 'edificio_id');
    }

    /** @return HasMany<PisoEloquentModel, $this> */
    public function pisos(): HasMany
    {
        return $this->hasMany(PisoEloquentModel::class, 'torre_id');
    }

    protected function casts(): array
    {
        return [
            'es_predeterminada' => 'boolean',
            'estado' => EstadoEstructura::class,
        ];
    }
}
