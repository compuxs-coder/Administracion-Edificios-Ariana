<?php

namespace Src\Edificio\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Src\Edificio\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class RolEloquentModel extends Model
{
    use UsesApplicationSchema;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'codigo';

    protected $fillable = ['codigo', 'nombre', 'descripcion', 'es_sistema'];

    public function getTable(): string
    {
        return $this->qualifiedTable('roles');
    }

    /** @return BelongsToMany<PermisoEloquentModel, $this> */
    public function permisos(): BelongsToMany
    {
        return $this->belongsToMany(
            PermisoEloquentModel::class,
            $this->qualifiedTable('rol_permisos'),
            'rol_codigo',
            'permiso_codigo',
        );
    }

    protected function casts(): array
    {
        return ['es_sistema' => 'boolean'];
    }
}
