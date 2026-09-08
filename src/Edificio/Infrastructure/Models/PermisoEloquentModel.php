<?php

namespace Src\Edificio\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Src\Edificio\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class PermisoEloquentModel extends Model
{
    use UsesApplicationSchema;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'codigo';

    protected $fillable = ['codigo', 'nombre', 'modulo'];

    public function getTable(): string
    {
        return $this->qualifiedTable('permisos');
    }
}
