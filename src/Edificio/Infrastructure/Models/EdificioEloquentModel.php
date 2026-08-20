<?php

namespace Src\Edificio\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Domain\Enums\EstadoEdificio;

final class EdificioEloquentModel extends Model
{
    use HasUuid;

    protected $fillable = [
        'id',
        'nombre',
        'ruc',
        'direccion',
        'ciudad',
        'telefono',
        'correo',
        'responsable',
        'estado',
        'created_at',
        'updated_at',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('edificios');
    }

    /** @return BelongsToMany<UserEloquentModel, $this> */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(
            UserEloquentModel::class,
            $this->qualifiedTable('edificio_usuario'),
            'edificio_id',
            'user_id',
        )->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoEdificio::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    private function qualifiedTable(string $table): string
    {
        if ($this->getConnection()->getDriverName() !== 'pgsql') {
            return $table;
        }

        return config('database.application_schema').'.'.$table;
    }
}
